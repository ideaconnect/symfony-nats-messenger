<?php

/**
 * Retrying through copies - with `retry_via: copy`, the default, Symfony's retry strategy decides whether and when a
 * failed message is retried, and the retry reaches NATS as a copy: a new message on the same subject, which NATS
 * delivers at once unless `scheduled_messages` makes it wait.
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners, as `messenger:consume` does, over:
 *
 * 1. A message that keeps failing, with the retry strategy { max_retries: 2, delay: 1000, multiplier: 2 }. It runs 3
 *    times, at retry counts 0 to 2, and each retry is a copy that runs at once instead of 1 and 2 s later: a copy
 *    waits only with `scheduled_messages`, and the transport warns once that the delay was dropped. Symfony then gives
 *    up: the last copy is TERMed, the failure transport gets one copy, and the stream holds the message and its two
 *    retry copies. The worker settles each delivery it retried with an ACK (Symfony Messenger 7.4.22, 8.1.10 and
 *    later) or a TERM (earlier releases), so only the TERM of the last copy comes on every release.
 * 2. A message Symfony gives up on without a failure transport, under `copy` and `nak`, on a stream with limits,
 *    work-queue and interest retention. The worker rejects it, which symfony mode TERMs: a limits stream keeps it,
 *    with its retry copy under `copy`, and the other two remove it, as they remove an acknowledged message.
 * 3. An unrecoverable failure: it runs once, nothing is retried, and the failure transport gets one copy.
 * 4. A RecoverableMessageHandlingException until the fourth run, with max_retries 1: Symfony retries it whatever
 *    max_retries says, so it is handled on the fourth run, with three retry copies in the stream and nothing in the
 *    failure transport.
 * 5. A message that cannot be decoded, with the transport's igbinary serializer. On Symfony Messenger 8.1.11 and
 *    later the worker gets it as the decoding failure and fails it once, as unrecoverable: a TERM and one failure
 *    copy. Before 8.1 the transport TERMs it at once and get() throws the decode error, as Symfony's own transports
 *    do. 8.1.0 to 8.1.10 retry it, which this example does not show.
 *
 * examples/retry-via-nak.php shows the same failures with `retry_via: nak`, and examples/retry-via-copy-or-nak.php
 * compares the two.
 *
 * Mirrors the README "Retry Handler Behavior" section. Needs the test server (composer nats:start).
 * Run: php examples/retry-via-copy.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Composer\InstalledVersions;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

$lines = [];
$messenger = (string) InstalledVersions::getVersion('symfony/messenger');
$fails = static function (): void {
    throw new RuntimeException('the payment provider did not answer');
};

// 1. A message that keeps failing.
$stream = exampleStream('retry_via_copy');
$logger = new ExampleLogger();
$transport = retryTransport($stream, '', $logger);
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    $transport->send(new Envelope(new ExampleMessage('keeps failing')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(2, 1000, 2, 0, 0.0),
        $fails,
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
        quiet: 1.0,
    );
    $advisories = $advisories();
    $gaps = $run->gaps();

    assertExpected('the retry count of each run', [0, 1, 2], $run->retryCounts());
    assertExpected('willRetry() of each failure', [true, true, false], $run->willRetry);
    assertSecondsBetween('the first retry, a copy whose delay of 1 s was dropped', $gaps[0], 0.0, 0.9);
    assertSecondsBetween('the second retry, a copy whose delay of 2 s was dropped', $gaps[1], 0.0, 0.9);
    assertExpected('a TERM for the copy Symfony gave up on', true, ($advisories['MSG_TERMINATED'] ?? 0) >= 1);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());
    assertExpected('messages in the stream: the message and its two retry copies', 3, storedMessages($stream));
    assertExpected('deliveries that wait for an acknowledgement', 0, consumerCounts($stream)['ackPending']);
    assertExpected(
        'the warning that the retry was published without its delay of 1000 ms',
        1,
        count(array_filter(warningsLogged($logger), static fn (string $line): bool => str_contains($line, "Symfony's retry was published at once, without its delay of 1000 ms"))),
    );

    $lines[] = sprintf(
        'a message that keeps failing ran 3 times, at retry counts 0 to 2, each retry a copy that ran %s after the failure before instead of the 1 and 2 s the strategy asked for, as a copy waits only with scheduled_messages; Symfony then gave up: a TERM, 1 copy in the failure transport, 3 messages in the stream, and one warning that the delay was dropped',
        secondsList($gaps),
    );
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 2. Given up on without a failure transport, on each kind of stream.
$stored = [];
foreach (['copy', 'nak'] as $via) {
    foreach (['limits', 'workqueue', 'interest'] as $retention) {
        $stream = exampleStream('retry_via_copy');
        $transport = retryTransport($stream, 'retry_via=' . $via . '&stream_retention=' . $retention);
        try {
            $transport->setup();
            $transport->send(new Envelope(new ExampleMessage('given up on')));

            $run = runWorker(
                $transport,
                new MultiplierRetryStrategy(1, 100, 1, 0, 0.0),
                $fails,
                static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
                quiet: 0.5,
                failureTransport: false,
            );

            assertExpected(sprintf('the retry count of each run, %s on a %s stream', $via, $retention), [0, 1], $run->retryCounts());
            assertExpected(sprintf('willRetry() of each failure, %s on a %s stream', $via, $retention), [true, false], $run->willRetry);
            assertExpected(sprintf('deliveries that wait for an acknowledgement, %s on a %s stream', $via, $retention), 0, consumerCounts($stream)['ackPending']);
            $stored[$via][$retention] = storedMessages($stream);
        } finally {
            $transport->close();
            deleteExampleStream($stream);
        }
    }
}
assertExpected('messages left in each stream under copy', ['limits' => 2, 'workqueue' => 0, 'interest' => 0], $stored['copy']);
assertExpected('messages left in each stream under nak', ['limits' => 1, 'workqueue' => 0, 'interest' => 0], $stored['nak']);
$lines[] = 'a message given up on without a failure transport was TERMed: a limits stream kept it, with its retry copy under copy (2 messages) and alone under nak (1), and a work-queue and an interest stream removed it (0)';

// 3. An unrecoverable failure.
$stream = exampleStream('retry_via_copy');
$transport = retryTransport($stream, '');
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    $transport->send(new Envelope(new ExampleMessage('an invoice that does not exist')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(1, 100, 1, 0, 0.0),
        static function (): void {
            throw new UnrecoverableMessageHandlingException('the invoice does not exist');
        },
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
        quiet: 1.0,
    );
    $advisories = $advisories();

    assertExpected('the runs of an unrecoverable failure', [0], $run->retryCounts());
    assertExpected('willRetry() of an unrecoverable failure', [false], $run->willRetry);
    assertExpected('copies of it in the failure transport', 1, $run->failureCopies());
    assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);
    assertExpected('messages in the stream, no retry copy among them', 1, storedMessages($stream));

    $lines[] = 'an unrecoverable failure ran once, was TERMed and left 1 copy in the failure transport, and no retry copy';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 4. A recoverable failure that lasts longer than max_retries allows.
$stream = exampleStream('retry_via_copy');
$transport = retryTransport($stream, '');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('waits for a service')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(1, 100, 1, 0, 0.0),
        static function (ExampleMessage $message, WorkerRun $run): void {
            if (count($run->received) < 4) {
                throw new RecoverableMessageHandlingException('the payment provider is down');
            }
        },
        static fn (WorkerRun $run): bool => $run->handled !== [] || in_array(false, $run->willRetry, true),
    );

    assertExpected('the retry count of each run', [0, 1, 2, 3], $run->retryCounts());
    assertExpected('messages handled', 1, count($run->handled));
    assertExpected('copies in the failure transport', 0, $run->failureCopies());
    assertExpected('messages in the stream: the message and its three retry copies', 4, storedMessages($stream));

    $lines[] = 'with max_retries 1, a message whose handler threw a RecoverableMessageHandlingException until its fourth run was handled on the fourth, at retry count 3, with 4 messages in the stream and nothing in the failure transport';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 5. A message that cannot be decoded, with the transport's own serializer.
$passesItOn = (new ReflectionClass(MessageDecodingFailedException::class))->hasMethod('wrap');
$stream = exampleStream('retry_via_copy');
$logger = new ExampleLogger();
$transport = new NatsTransport(exampleDsn($stream), ['max_batch_timeout' => 0.2], null, $logger);
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    // A payload that is igbinary, but not an envelope: what another application's publisher could put on the subject.
    $payload = (string) igbinary_serialize('not an envelope');
    withJetStream($stream, static fn (JetStreamContext $jetStream): mixed => $jetStream->publish($stream . '.messages', $payload)->await());

    if ($passesItOn && version_compare($messenger, '8.1.11', '>=')) {
        $run = runWorker(
            $transport,
            new MultiplierRetryStrategy(2, 200, 2, 0, 0.0),
            static function (): void {
            },
            static fn (WorkerRun $run): bool => $run->willRetry !== [],
            quiet: 1.0,
        );
        $advisories = $advisories();

        assertExpected('runs', 1, count($run->received));
        assertExpected('what the failure threw', [UnrecoverableMessageHandlingException::class], $run->failedWith);
        assertExpected('willRetry() of the failure', [false], $run->willRetry);
        assertExpected('copies in the failure transport', 1, $run->failureCopies());
        assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);
        assertExpected('messages in the stream, no retry copy among them', 1, storedMessages($stream));
        assertExpected('errors logged', [], $logger->linesAt('error'));

        $lines[] = 'a message the igbinary serializer could not decode went to the worker as the decoding failure, as Messenger 8.1 and later hand it on: it failed once as unrecoverable (8.1.11 and later), was TERMed, has 1 copy in the failure transport and no retry copy';
    } elseif (!$passesItOn) {
        $thrown = null;
        try {
            iterator_to_array($transport->get(), false);
        } catch (MessageDecodingFailedException $failure) {
            $thrown = $failure;
        }
        $later = receiveFor($transport, 1.5);
        $advisories = $advisories();

        assertExpected('get() threw a MessageDecodingFailedException', true, $thrown !== null);
        assertExpected('deliveries after the one that could not be decoded', 0, count($later));
        assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);

        $lines[] = "a message the igbinary serializer could not decode was TERMed at once, before Messenger 8.1: get() threw, as Symfony's own transports do, and it was not delivered again";
    } else {
        $advisories();
        $lines[] = sprintf('a message that cannot be decoded is not shown on Messenger %s: 8.1.0 to 8.1.10 retry a decoding failure a serializer threw', $messenger);
    }
    assertExpected('deliveries that wait for an acknowledgement', 0, consumerCounts($stream)['ackPending']);
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

printf(
    "OK retry-via-copy: Symfony's retry strategy decided and each retry went out as a copy, for %d cases (symfony/messenger %s):\n",
    count($lines),
    InstalledVersions::getPrettyVersion('symfony/messenger'),
);
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
