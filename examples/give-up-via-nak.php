<?php

/**
 * Dead in place - with `retry_via: nak` and `give_up_via: nak`, a message Symfony's retry strategy gives up on
 * stays in the stream. Such a transport must have no failure transport, and the factory refuses one.
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners, as `messenger:consume` does, with the
 * retry strategy { max_retries: 3, delay: 200, multiplier: 2 } and `max_deliver: 4`, which is max_retries + 1:
 *
 * 1. A message that keeps failing, on a stream with limits retention and on one with work-queue retention. It runs
 *    4 times. The last delivery gets a NAK without a delay, NATS publishes MAX_DELIVERIES and keeps the message.
 * 2. A message that fails with an UnrecoverableMessageHandlingException. On the limits stream it runs once and is
 *    TERMed, which keeps it there. On the work-queue stream, where a TERM would remove it, it is NAKed: it runs
 *    4 times back to back and stays.
 * 3. The factory refuses to create such a transport when it has a failure transport: the message would be copied
 *    there as well. The worker of cases 1 and 2 is wired by hand with a failure transport, which the factory never
 *    sees, so those cases show that copy: an application gets the refusal instead.
 *
 * Needs the test server (composer nats:start). Run: php examples/give-up-via-nak.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use IDCT\NatsMessenger\NatsTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

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
$named = '';
$lines = [];

// 1. A message that keeps failing.
foreach (['limits' => '', 'work-queue' => '&stream_retention=workqueue'] as $retention => $query) {
    $result = deadInPlace($named . $query, $always);
    $run = $result['run'];
    assertExpected("$retention: the retry count of each run", [0, 1, 2, 3], $run->retryCounts());
    assertExpected("$retention: willRetry() of each failure", [true, true, true, false], $run->willRetry);
    assertExpected("$retention: MAX_DELIVERIES advisories", 1, $result['advisories']['MAX_DELIVERIES'] ?? 0);
    assertExpected("$retention: MSG_TERMINATED advisories", 0, $result['advisories']['MSG_TERMINATED'] ?? 0);
    // The hand-wired failure transport of this worker; in an application the factory refuses the combination.
    assertExpected("$retention: copies in the hand-wired failure transport", 1, $run->failureCopies());
    assertExpected("$retention: messages in the stream", 1, $result['stored']);
    assertExpected("$retention: deliveries that wait for an acknowledgement", 0, $result['ackPending']);
    $lines[] = sprintf('%s stream: a message that keeps failing ran 4 times, its retries %s apart, then MAX_DELIVERIES; 1 message in the stream', $retention, secondsList($run->gaps()));
}

// 2. A failure Symfony never retries.
$result = deadInPlace($named, $never);
assertExpected('unrecoverable, limits: runs', 1, count($result['run']->received));
assertExpected('unrecoverable, limits: MSG_TERMINATED advisories', 1, $result['advisories']['MSG_TERMINATED'] ?? 0);
assertExpected('unrecoverable, limits: messages in the stream', 1, $result['stored']);
$lines[] = 'limits stream: an unrecoverable failure ran once and was TERMed; 1 message in the stream';

$result = deadInPlace($named . '&stream_retention=workqueue', $never);
assertExpected('unrecoverable, work-queue: runs', 4, count($result['run']->received));
assertExpected('unrecoverable, work-queue: MAX_DELIVERIES advisories', 1, $result['advisories']['MAX_DELIVERIES'] ?? 0);
assertExpected('unrecoverable, work-queue: MSG_TERMINATED advisories', 0, $result['advisories']['MSG_TERMINATED'] ?? 0);
assertExpected('unrecoverable, work-queue: messages in the stream', 1, $result['stored']);
$lines[] = sprintf('work-queue stream: an unrecoverable failure ran 4 times, %s apart, then MAX_DELIVERIES; 1 message in the stream', secondsList($result['run']->gaps()));

// 3. The factory, told as the bundle tells it that the transport "async" has a failure transport, refuses it.
$refusal = null;
try {
    (new NatsTransportFactory(null, ['async']))->createTransport(
        exampleDsn(exampleStream('give_up_via_nak'), 'retry_via=nak&give_up_via=nak&max_deliver=4'),
        ['transport_name' => 'async'],
        new PhpSerializer(),
    );
} catch (InvalidArgumentException $e) {
    $refusal = $e->getMessage();
}
assertExpected('the factory refuses a failure transport', true, is_string($refusal) && str_contains($refusal, "has give_up_via 'nak' and a failure transport"));
$lines[] = 'the factory refused such a transport with a failure transport';

echo 'OK give-up-via-nak: ' . implode('; ', $lines) . "\n";
