<?php

/**
 * Dead in place - with `retry_via: nak` and `give_up_via: nak`, a message Symfony's retry strategy gives up on
 * stays in the stream, and no failure transport gets a copy of it.
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners, as `messenger:consume` does, with the
 * retry strategy { max_retries: 3, delay: 200, multiplier: 2 } and `max_deliver: 4`, which is max_retries + 1:
 *
 * 1. A message that keeps failing, on a stream with limits retention and on one with work-queue retention. It runs
 *    4 times. The last delivery gets a NAK without a delay, NATS publishes MAX_DELIVERIES and keeps the message.
 * 2. A message that fails with an UnrecoverableMessageHandlingException. On the limits stream it runs once and is
 *    TERMed, which keeps it there. On the work-queue stream, where a TERM would remove it, it is NAKed: it runs
 *    4 times back to back and stays.
 * 3. The same as 1 on a transport that does not know its name: the failure transport gets a copy too.
 *
 * Needs the test server (composer nats:start). Run: php examples/give-up-via-nak.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

/**
 * @param \Closure(): \Throwable $failure
 * @return array{run: WorkerRun, advisories: array<string, int>, stored: int, ackPending: int}
 */
function deadInPlace(string $query, \Closure $failure): array
{
    $stream = exampleStream('give_up_via_nak');
    $transport = retryTransport($stream, 'retry_via=nak&give_up_via=nak&max_deliver=4' . $query);
    try {
        $transport->setup();
        $advisories = consumerAdvisories($stream);
        $transport->send(new Envelope(new ExampleMessage('keeps failing')));

        $run = runWorker(
            $transport,
            new MultiplierRetryStrategy(3, 200, 2, 0, 0.0),
            static function () use ($failure): void {
                throw $failure();
            },
            static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
            quiet: 1.5,
        );

        return ['run' => $run, 'advisories' => $advisories(), 'stored' => storedMessages($stream), 'ackPending' => consumerCounts($stream)['ackPending']];
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }
}

$always = static fn (): \Throwable => new RuntimeException('the payment provider did not answer');
$never = static fn (): \Throwable => new UnrecoverableMessageHandlingException('the invoice does not exist');
// The Worker of these examples names its transport "async", as FrameworkBundle would pass it.
$named = '&transport_name=async';
$lines = [];

// 1. A message that keeps failing.
foreach (['limits' => '', 'work-queue' => '&stream_retention=workqueue'] as $retention => $query) {
    $result = deadInPlace($named . $query, $always);
    $run = $result['run'];
    assertExpected("$retention: the retry count of each run", [0, 1, 2, 3], $run->retryCounts());
    assertExpected("$retention: willRetry() of each failure", [true, true, true, false], $run->willRetry);
    assertExpected("$retention: MAX_DELIVERIES advisories", 1, $result['advisories']['MAX_DELIVERIES'] ?? 0);
    assertExpected("$retention: MSG_TERMINATED advisories", 0, $result['advisories']['MSG_TERMINATED'] ?? 0);
    assertExpected("$retention: copies in the failure transport", 0, $run->failureCopies());
    assertExpected("$retention: messages in the stream", 1, $result['stored']);
    assertExpected("$retention: deliveries that wait for an acknowledgement", 0, $result['ackPending']);
    $lines[] = sprintf('%s stream: a message that keeps failing ran 4 times, its retries %s apart, then MAX_DELIVERIES; 1 message in the stream, 0 failure-transport copies', $retention, secondsList($run->gaps()));
}

// 2. A failure Symfony never retries.
$result = deadInPlace($named, $never);
assertExpected('unrecoverable, limits: runs', 1, count($result['run']->received));
assertExpected('unrecoverable, limits: MSG_TERMINATED advisories', 1, $result['advisories']['MSG_TERMINATED'] ?? 0);
assertExpected('unrecoverable, limits: messages in the stream', 1, $result['stored']);
assertExpected('unrecoverable, limits: copies in the failure transport', 0, $result['run']->failureCopies());
$lines[] = 'limits stream: an unrecoverable failure ran once and was TERMed; 1 message in the stream, 0 failure-transport copies';

$result = deadInPlace($named . '&stream_retention=workqueue', $never);
assertExpected('unrecoverable, work-queue: runs', 4, count($result['run']->received));
assertExpected('unrecoverable, work-queue: MAX_DELIVERIES advisories', 1, $result['advisories']['MAX_DELIVERIES'] ?? 0);
assertExpected('unrecoverable, work-queue: MSG_TERMINATED advisories', 0, $result['advisories']['MSG_TERMINATED'] ?? 0);
assertExpected('unrecoverable, work-queue: messages in the stream', 1, $result['stored']);
assertExpected('unrecoverable, work-queue: copies in the failure transport', 0, $result['run']->failureCopies());
$lines[] = sprintf('work-queue stream: an unrecoverable failure ran 4 times, %s apart, then MAX_DELIVERIES; 1 message in the stream, 0 failure-transport copies', secondsList($result['run']->gaps()));

// 3. Without the transport's name the failure transport listener is not told to skip the message.
$result = deadInPlace('', $always);
assertExpected('no name: messages in the stream', 1, $result['stored']);
assertExpected('no name: copies in the failure transport', 1, $result['run']->failureCopies());
$lines[] = 'a transport that does not know its name: the message stays in the stream and the failure transport gets 1 copy as well';

echo 'OK give-up-via-nak: ' . implode('; ', $lines) . "\n";
