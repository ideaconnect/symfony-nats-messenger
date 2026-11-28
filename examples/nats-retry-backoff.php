<?php

/**
 * NATS retry handler with a backoff - in nats mode NATS redelivers a failed message itself, and a consumer's
 * `backoff` spaces out the redeliveries of the NAKs that carry `nak_delay`, and of the deliveries nobody settled.
 *
 * Runs Symfony's own Worker and retry listener with `max_retries: 0`, as the README asks for in nats mode, over a
 * message whose handler always fails, with `max_deliver: 3` and `backoff: [1, 2]`:
 *
 * 1. With `nak_delay: 0.5`: NATS adds backoff[n] - backoff[0] to each NAK that carries a delay, where n counts the
 *    earlier deliveries, so the redeliveries come 0.5 and 1.5 s after the failures. After the third delivery NATS
 *    redelivers nothing and publishes MAX_DELIVERIES; the stream keeps the message.
 * 2. Without `nak_delay`: a plain NAK is redelivered at once, whatever the backoff says. That is why the README pairs
 *    `backoff` with `nak_delay` to space out failed messages.
 * 3. A delivery nobody settled, as a crashed worker leaves it: the backoff replaces `ack_wait`, so it comes back
 *    backoff[0] = 1 s later, and the next one backoff[1] = 2 s after that.
 *
 * With `retry_via: nak` in symfony mode the builder refuses `backoff`, since NATS would lengthen Symfony's delays
 * (examples/retry-via-nak-server-behaviour.php).
 *
 * Mirrors the README "Retry Handler Behavior" section. Needs the test server (composer nats:start).
 * Run: php examples/nats-retry-backoff.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

$lines = [];
$fails = static function (): void {
    throw new RuntimeException('the payment provider did not answer');
};

// 1. and 2. A message that keeps failing, with and without nak_delay.
$gapsOf = [];
foreach (['nak_delay 0.5 s' => '&nak_delay=0.5', 'no nak_delay' => ''] as $case => $nakDelay) {
    $stream = exampleStream('nats_retry_backoff');
    $transport = retryTransport($stream, 'retry_handler=nats&max_deliver=3&backoff[]=1&backoff[]=2' . $nakDelay);
    try {
        $transport->setup();
        $advisories = consumerAdvisories($stream);
        $transport->send(new Envelope(new ExampleMessage('keeps failing')));

        $run = runWorker(
            $transport,
            new MultiplierRetryStrategy(0),
            $fails,
            static fn (WorkerRun $run): bool => count($run->received) >= 3,
            quiet: 3.0,
            failureTransport: false,
        );
        $advisories = $advisories();
        $gapsOf[$case] = $run->gaps();

        assertExpected('the runs, ' . $case, 3, count($run->received));
        assertExpected('MAX_DELIVERIES advisories, ' . $case, 1, $advisories['MAX_DELIVERIES'] ?? 0);
        assertExpected('messages in the stream, ' . $case, 1, storedMessages($stream));
        assertExpected('deliveries that wait for an acknowledgement, ' . $case, 0, consumerCounts($stream)['ackPending']);
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }
}
assertSecondsBetween('the first redelivery, nak_delay 0.5 s (backoff adds 1 - 1 s)', $gapsOf['nak_delay 0.5 s'][0], 0.49, 2.0);
assertSecondsBetween('the second redelivery, nak_delay 0.5 s (backoff adds 2 - 1 s)', $gapsOf['nak_delay 0.5 s'][1], 1.49, 3.0);
assertSecondsBetween('the first redelivery of a plain NAK', $gapsOf['no nak_delay'][0], 0.0, 0.5);
assertSecondsBetween('the second redelivery of a plain NAK', $gapsOf['no nak_delay'][1], 0.0, 0.5);
$lines[] = sprintf(
    'with nak_delay 0.5 s a message that keeps failing was redelivered %s after its failures, as NATS added backoff[n] - backoff[0] to each NAK, and then stopped at max_deliver 3 with MAX_DELIVERIES',
    secondsList($gapsOf['nak_delay 0.5 s']),
);
$lines[] = sprintf('without nak_delay the plain NAKs were redelivered %s after the failures: the backoff did not space them out', secondsList($gapsOf['no nak_delay']));

// 3. A delivery nobody settled.
$stream = exampleStream('nats_retry_backoff');
$transport = retryTransport($stream, 'retry_handler=nats&max_deliver=3&backoff[]=1&backoff[]=2');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('the worker crashed')));

    $deliveries = [receiveOne($transport, 5.0), receiveOne($transport, 10.0), receiveOne($transport, 10.0)];
    $transport->ack($deliveries[2]['envelope']);
    $gaps = [$deliveries[1]['at'] - $deliveries[0]['at'], $deliveries[2]['at'] - $deliveries[1]['at']];

    assertSecondsBetween('the redelivery of the first delivery nobody settled (backoff[0] 1 s)', $gaps[0], 0.95, 2.5);
    assertSecondsBetween('the redelivery of the second delivery nobody settled (backoff[1] 2 s)', $gaps[1], 1.95, 3.5);

    $lines[] = sprintf('deliveries nobody settled came back %s later: the backoff replaced ack_wait', secondsList($gaps));
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

echo "OK nats-retry-backoff: with retry_handler nats, max_deliver 3 and backoff 1 and 2 s:\n";
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
