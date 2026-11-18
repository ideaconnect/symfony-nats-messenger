<?php

/**
 * retry_via: copy or nak - the same failure under both, to show what differs: who else sees the retry, what the
 * stream stores, what a waiting retry holds back, and how exact its delay is (5.9.0).
 *
 * In symfony mode, Symfony's retry strategy decides whether and when a failed message is retried. `retry_via`
 * chooses how that retry reaches NATS: as a copy, a new message on the same subject (the default), or as a NAK of
 * the failed delivery with Symfony's delay, after which NATS redelivers the original.
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners over a message that fails once:
 *
 * 1. With a second consumer on the subject, as another application reading the same stream has: under `copy` it
 *    receives the message and its retry copy, and the stream stores 2 messages; under `nak` it receives the one
 *    message, and the stream stores 1.
 * 2. With `max_ack_pending: 1`: a retry that waits as a NAK holds one of the consumer's `max_ack_pending` slots
 *    (1000 by default), so the next message waits for it; a copy holds nothing.
 * 3. With a retry delay of 0.3 s: a copy is delivered at once, since its delay is dropped; with
 *    `scheduled_messages` (NATS 2.12 and later) it waits until the next whole second; a NAK waits 0.3 s, on any
 *    server.
 *
 * Mirrors the README "Retrying through a NAK" section. Needs the test server (composer nats:start).
 * Run: php examples/retry-via-copy-or-nak.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

$lines = [];

/** A handler that fails the message on its first run only. */
$failsOnce = static function (ExampleMessage $message, WorkerRun $run): void {
    if (count($run->received) === 1) {
        throw new RuntimeException('the payment provider did not answer');
    }
};

// 1. A second consumer on the subject.
$seen = [];
foreach (['copy', 'nak'] as $via) {
    $stream = exampleStream('retry_via_compare');
    $worker = retryTransport($stream, 'retry_via=' . $via . '&consumer=billing');
    $other = retryTransport($stream, 'consumer=audit');
    try {
        $worker->setup();
        $other->setup();
        $worker->send(new Envelope(new ExampleMessage('invoice 7')));

        $run = runWorker($worker, new MultiplierRetryStrategy(3, 200, 2, 0, 0.0), $failsOnce, static fn (WorkerRun $run): bool => $run->handled !== []);
        $seen[$via] = ['other' => count(receiveFor($other, 1.5)), 'stored' => storedMessages($stream)];

        assertExpected('the retry count of each run under ' . $via, [0, 1], $run->retryCounts());
    } finally {
        $worker->close();
        $other->close();
        deleteExampleStream($stream);
    }
}
assertExpected('what the other consumer received and the stream stored under copy', ['other' => 2, 'stored' => 2], $seen['copy']);
assertExpected('what the other consumer received and the stream stored under nak', ['other' => 1, 'stored' => 1], $seen['nak']);
$lines[] = 'a second consumer on the subject received 2 messages under copy, the retry copy among them, and the stream stored 2; under nak it received 1, and the stream stored 1';

// 2. What a waiting retry holds back, with max_ack_pending 1.
$secondAfter = [];
foreach (['copy', 'nak'] as $via) {
    $stream = exampleStream('retry_via_compare');
    $worker = retryTransport($stream, 'retry_via=' . $via . '&max_ack_pending=1');
    try {
        $worker->setup();
        $worker->send(new Envelope(new ExampleMessage('first')));
        $worker->send(new Envelope(new ExampleMessage('second')));

        $failedAt = null;
        $run = runWorker(
            $worker,
            new MultiplierRetryStrategy(3, 1500, 1, 0, 0.0),
            static function (ExampleMessage $message) use (&$failedAt): void {
                if ($message->text === 'first' && $failedAt === null) {
                    $failedAt = microtime(true);
                    throw new RuntimeException('the payment provider did not answer');
                }
            },
            static fn (WorkerRun $run): bool => count($run->handled) >= 2,
        );

        assertExpected('messages handled under ' . $via, 2, count($run->handled));
        $secondAfter[$via] = (float) $run->handledAt('second') - (float) $failedAt;
    } finally {
        $worker->close();
        deleteExampleStream($stream);
    }
}
assertSecondsBetween('the next message under copy, which holds nothing', $secondAfter['copy'], 0.0, 1.0);
assertSecondsBetween('the next message under nak, behind a retry that waits 1.5 s', $secondAfter['nak'], 1.45, 4.0);
$lines[] = sprintf(
    'with max_ack_pending 1 and a retry that waits 1.5 s, the next message was handled %.2f s after the failure under copy and %.2f s after it under nak, where the waiting retry held the one slot',
    $secondAfter['copy'],
    $secondAfter['nak'],
);

// 3. How long a 0.3 s retry waits.
$variants = ['copy' => 'retry_via=copy', 'nak' => 'retry_via=nak'];
$schedules = serverHasMessageSchedules();
if ($schedules) {
    $variants['copy with scheduled_messages'] = 'retry_via=copy&scheduled_messages=true';
}
$waited = [];
foreach ($variants as $name => $query) {
    $stream = exampleStream('retry_via_compare');
    $worker = retryTransport($stream, $query);
    try {
        $worker->setup();
        $worker->send(new Envelope(new ExampleMessage('invoice 7')));

        $run = runWorker($worker, new MultiplierRetryStrategy(3, 300, 2, 0, 0.0), $failsOnce, static fn (WorkerRun $run): bool => $run->handled !== []);

        assertExpected('messages handled, ' . $name, 1, count($run->handled));
        $waited[$name] = $run->gaps()[0];
    } finally {
        $worker->close();
        deleteExampleStream($stream);
    }
}
assertSecondsBetween('a 0.3 s retry as a copy, whose delay is dropped', $waited['copy'], 0.0, 1.0);
assertSecondsBetween('a 0.3 s retry as a NAK', $waited['nak'], 0.29, 1.8);
if ($schedules) {
    assertSecondsBetween('a 0.3 s retry as a scheduled copy, at the next whole second', $waited['copy with scheduled_messages'], 0.29, 2.8);
    $lines[] = sprintf(
        'a retry with a delay of 0.3 s ran %.2f s later as a copy, %.2f s later as a copy with scheduled_messages (held until the next whole second) and %.2f s later as a NAK',
        $waited['copy'],
        $waited['copy with scheduled_messages'],
        $waited['nak'],
    );
} else {
    $lines[] = sprintf(
        'a retry with a delay of 0.3 s ran %.2f s later as a copy and %.2f s later as a NAK; a copy with scheduled_messages cannot be shown on NATS %s, which has no message schedules (2.12 and later have)',
        $waited['copy'],
        $waited['nak'],
        serverVersion(),
    );
}

echo "OK retry-via-copy-or-nak: a message that fails once, retried as a copy and as a NAK:\n";
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
