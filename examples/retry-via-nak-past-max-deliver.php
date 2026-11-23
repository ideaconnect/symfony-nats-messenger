<?php

/**
 * Retrying through a NAK, past max_deliver - with `retry_via: nak`, the retry of the last delivery `max_deliver`
 * allows is published as a copy, so Symfony's retry strategy runs to its end whatever `max_deliver` is (5.9.0).
 *
 * NATS redelivers nothing after the last delivery `max_deliver` allows, whatever a NAK says. A retry Symfony
 * decides on for that delivery would not happen, and since Symfony retried it, no failure transport would get the
 * message either. So the transport publishes Symfony's retry copy for that one delivery, as `retry_via: copy`
 * does for every retry: a new message, with the retry count Symfony gave it and deliveries of its own.
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners, on a transport with `max_deliver: 2`:
 *
 * 1. A message that keeps failing, with max_retries 3. It runs 4 times, at retry counts 0 to 3: the retry of the
 *    second delivery goes out as a copy, which runs at once, and that copy's own retry is a NAK again. Then
 *    Symfony gives up, with one copy in the failure transport. NATS publishes no MAX_DELIVERIES advisory, since
 *    no delivery ended that way. The transport warns once that the copy went out without its delay (5.10.0).
 * 2. A message whose handler throws a RecoverableMessageHandlingException until the service it needs is back, on
 *    the fifth run. Symfony retries such an exception past max_retries, so no `max_deliver` would be high enough.
 *    It is handled on the fifth run.
 *
 * `max_deliver` is optional here: left unset, or set above the retries you expect, it only caps deliveries
 * nobody settles.
 *
 * Mirrors the README "Retrying through a NAK" section. Needs the test server (composer nats:start).
 * Run: php examples/retry-via-nak-past-max-deliver.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Composer\InstalledVersions;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

$lines = [];

// 1. max_deliver 2 with max_retries 3: a message that keeps failing.
$stream = exampleStream('retry_via_last');
$logger = new ExampleLogger();
$transport = retryTransport($stream, 'retry_via=nak&max_deliver=2', $logger);
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    $transport->send(new Envelope(new ExampleMessage('keeps failing')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(3, 200, 2, 0, 0.0),
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
    assertSecondsBetween('the first retry, a NAK (delay 200 ms)', $gaps[0], 0.19, 1.7);
    assertSecondsBetween('the second retry, a copy, which does not wait', $gaps[1], 0.0, 1.0);
    assertSecondsBetween("the third retry, a NAK of the copy's first delivery (delay 800 ms)", $gaps[2], 0.79, 2.3);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());
    assertExpected('messages in the stream', 2, storedMessages($stream));
    assertExpected('MAX_DELIVERIES advisories', 0, $advisories['MAX_DELIVERIES'] ?? 0);
    assertExpected('a TERM for the delivery Symfony gave up on', true, ($advisories['MSG_TERMINATED'] ?? 0) >= 1);
    assertExpected('warnings logged', 1, count(warningsLogged($logger)));
    assertExpected(
        'the warning that the copy went out without its delay of 400 ms',
        true,
        str_contains(warningsLogged($logger)[0] ?? '', 'the retry of the last delivery the consumer allows was published as a copy at once, without its delay of 400 ms'),
    );

    $lines[] = sprintf(
        'with max_retries 3, a message that keeps failing ran 4 times, at retry counts 0 to 3, the retries %s after the failure before: the second one, of the last delivery max_deliver allows, went out as a copy and ran at once; then a TERM and 1 copy in the failure transport, 2 messages in the stream, no MAX_DELIVERIES advisory, and one warning that the copy lost its delay',
        secondsList($gaps),
    );
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 2. A recoverable failure that lasts longer than max_deliver and max_retries allow.
$stream = exampleStream('retry_via_last');
$transport = retryTransport($stream, 'retry_via=nak&max_deliver=2');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('waits for a service')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(1, 200, 1, 0, 0.0),
        static function (ExampleMessage $message, WorkerRun $run): void {
            if (count($run->received) < 5) {
                throw new RecoverableMessageHandlingException('the payment provider is down');
            }
        },
        static fn (WorkerRun $run): bool => $run->handled !== [] || in_array(false, $run->willRetry, true),
    );

    assertExpected('the retry count of each run', [0, 1, 2, 3, 4], $run->retryCounts());
    assertExpected('messages handled', 1, count($run->handled));
    assertExpected('copies in the failure transport', 0, $run->failureCopies());

    $lines[] = 'with max_retries 1, a message whose handler threw a RecoverableMessageHandlingException until its fifth run was handled on the fifth, at retry count 4, with nothing in the failure transport';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

printf("OK retry-via-nak-past-max-deliver: max_deliver 2 did not end Symfony's retries (symfony/messenger %s):\n", InstalledVersions::getPrettyVersion('symfony/messenger'));
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
