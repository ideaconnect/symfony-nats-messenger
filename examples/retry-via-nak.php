<?php

/**
 * Retrying through a NAK - with `retry_via: nak`, Symfony's retry strategy decides whether and when a failed
 * message is retried, and NATS carries the retry out: the transport NAKs the failed delivery with the delay
 * Symfony computed, and NATS redelivers the original (5.9.0).
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners, as `messenger:consume` does, over
 * five messages:
 *
 * 1. One that keeps failing, with the retry strategy { max_retries: 3, delay: 200, multiplier: 2, max_delay: 500 }.
 *    It runs 4 times, the retries 0.2, 0.4 and 0.5 s after the failure before, with retry counts 0 to 3. Symfony
 *    then gives up: the delivery is TERMed, the failure transport gets one copy, and the stream still holds the
 *    one message. Nothing is logged, since Symfony's retry strategy is on by intent.
 * 2. One that fails once and is handled on its retry.
 * 3. One that fails with a RecoverableMessageHandlingException that names its own delay, which replaces the
 *    strategy's.
 * 4. One that fails with an UnrecoverableMessageHandlingException: it runs once and goes to the failure transport.
 * 5. One retried by a strategy without a delay: a NAK without a delay, which NATS redelivers at once.
 *
 * With the default, `retry_via: copy`, each retry would be a new message in the stream, delivered at once unless
 * `scheduled_messages` is on (examples/retry-via-copy-or-nak.php compares the two).
 *
 * Mirrors the README "Retrying through a NAK" section. Needs the test server (composer nats:start).
 * Run: php examples/retry-via-nak.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Composer\InstalledVersions;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

$lines = [];

// 1. A message that keeps failing.
$stream = exampleStream('retry_via_nak');
$logger = new ExampleLogger();
$transport = retryTransport($stream, 'retry_via=nak', $logger);
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    $transport->send(new Envelope(new ExampleMessage('keeps failing')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(3, 200, 2, 500, 0.0),
        static function (): void {
            throw new RuntimeException('the payment provider did not answer');
        },
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
        quiet: 1.0,
    );
    $advisories = $advisories();
    $gaps = $run->gaps();

    assertExpected('the retry count of each run', [0, 1, 2, 3], $run->retryCounts());
    assertExpected('willRetry() of each failure', [true, true, true, false], $run->willRetry);
    assertSecondsBetween('the first retry (delay 200 ms)', $gaps[0], 0.19, 1.7);
    assertSecondsBetween('the second retry (200 ms x 2)', $gaps[1], 0.39, 1.9);
    assertSecondsBetween('the third retry (800 ms, capped by max_delay 500 ms)', $gaps[2], 0.49, 2.0);
    assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);
    assertExpected('MAX_DELIVERIES advisories', 0, $advisories['MAX_DELIVERIES'] ?? 0);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());
    assertExpected('messages in the stream', 1, storedMessages($stream));
    assertExpected('deliveries that wait for an acknowledgement', 0, consumerCounts($stream)['ackPending']);
    assertExpected('warnings logged', [], warningsLogged($logger));

    $lines[] = sprintf(
        'a message that keeps failing ran 4 times, its retries %s after the failure before (the third capped by max_delay), with retry counts 0 to 3; Symfony then gave up: a TERM, 1 copy in the failure transport, 1 message in the stream, no warning logged',
        secondsList($gaps),
    );
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 2. to 5. One message each, on a stream of its own.
$cases = [
    'fails once' => [
        new MultiplierRetryStrategy(3, 200, 2, 0, 0.0),
        static fn (): Throwable => new RuntimeException('the payment provider did not answer'),
    ],
    'recoverable, with its own delay' => [
        new MultiplierRetryStrategy(3, 200, 2, 0, 0.0),
        static fn (): Throwable => new RecoverableMessageHandlingException('the payment provider asks to come back later', retryDelay: 700),
    ],
    'unrecoverable' => [
        new MultiplierRetryStrategy(3, 200, 2, 0, 0.0),
        static fn (): Throwable => new UnrecoverableMessageHandlingException('the invoice does not exist'),
    ],
    'retried without a delay' => [
        new MultiplierRetryStrategy(3, 0, 1, 0, 0.0),
        static fn (): Throwable => new RuntimeException('the payment provider did not answer'),
    ],
];

foreach ($cases as $text => [$strategy, $failure]) {
    $stream = exampleStream('retry_via_nak');
    $transport = retryTransport($stream, 'retry_via=nak');
    try {
        $transport->setup();
        $transport->send(new Envelope(new ExampleMessage($text)));

        // The handler fails on its first run only. An unrecoverable failure never gets a second one.
        $run = runWorker(
            $transport,
            $strategy,
            static function (ExampleMessage $message, WorkerRun $run) use ($failure): void {
                if (count($run->received) === 1) {
                    throw $failure();
                }
            },
            static fn (WorkerRun $run): bool => $run->handled !== [] || in_array(false, $run->willRetry, true),
            quiet: $text === 'unrecoverable' ? 1.0 : 0.0,
        );
        $gaps = $run->gaps();

        if ($text === 'unrecoverable') {
            assertExpected('the runs of an unrecoverable failure', [0], $run->retryCounts());
            assertExpected('willRetry() of an unrecoverable failure', [false], $run->willRetry);
            assertExpected('copies of it in the failure transport', 1, $run->failureCopies());
            $lines[] = 'an unrecoverable failure ran once and left 1 copy in the failure transport';

            continue;
        }

        assertExpected('the retry count of each run of "' . $text . '"', [0, 1], $run->retryCounts());
        assertExpected('messages handled of "' . $text . '"', 1, count($run->handled));
        assertExpected('copies of "' . $text . '" in the failure transport', 0, $run->failureCopies());
        assertExpected('messages in the stream of "' . $text . '"', 1, storedMessages($stream));

        if ($text === 'fails once') {
            assertSecondsBetween('the retry of a message that fails once', $gaps[0], 0.19, 1.7);
            $lines[] = sprintf('a message that failed once was handled on its retry, %.2f s later', $gaps[0]);
        } elseif ($text === 'recoverable, with its own delay') {
            assertSecondsBetween('the retry a recoverable exception asked for (700 ms)', $gaps[0], 0.69, 2.2);
            $lines[] = sprintf("a RecoverableMessageHandlingException's own delay of 700 ms replaced the strategy's 200 ms: retried %.2f s later", $gaps[0]);
        } else {
            assertSecondsBetween('a retry without a delay', $gaps[0], 0.0, 1.0);
            $lines[] = sprintf('a retry without a delay was a plain NAK, redelivered at once (%.2f s)', $gaps[0]);
        }
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }
}

printf(
    "OK retry-via-nak: Symfony's retry strategy decided and NATS redelivered the original after a NAK with Symfony's delay, for %d messages (symfony/messenger %s):\n",
    count($lines),
    InstalledVersions::getPrettyVersion('symfony/messenger'),
);
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
