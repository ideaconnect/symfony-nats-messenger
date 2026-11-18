<?php

/**
 * Retrying through a NAK, what the server does with it - a NAK retry is held by the NATS server, so the server's
 * limits apply to it: a delay it cannot hold, a message that expires while it waits, and a consumer with a
 * `backoff` (5.9.0).
 *
 * The Worker's part is made by hand here, so that each retry gets exactly the delay the case needs: the transport
 * is given the retry copy Symfony's retry listener would send, with its DelayStamp, and then the worker's ack() of
 * the failed delivery, which the transport turns into the NAK.
 *
 * 1. A delay too long for the server, a DelayStamp of PHP_INT_MAX: the transport cuts it at the longest delay the
 *    client can send (2^63 ns, about 292 years), and the server holds the message. It is still waiting for its
 *    acknowledgement when `ack_wait` has run out twice, so a NAK with a delay is not cut short by `ack_wait`.
 * 2. A retry that waits longer than `stream_max_age`: the message leaves the stream before its retry, and is never
 *    redelivered. Keep the retry strategy's `max_delay` below `stream_max_age`.
 * 3. A consumer with a `backoff`, as another transport's setup left it: NATS adds `backoff[n] - backoff[0]` to
 *    each NAK that carries a delay, where n counts the earlier deliveries. With backoff 1, 2, 3 s, retries of
 *    0.5 s come back after 0.5, 1.5 and 2.5 s. That is why the builder refuses `backoff` with `retry_via: nak`;
 *    run `messenger:setup-transports` after taking a `backoff` out, since a consumer that still has one keeps
 *    adding it.
 *
 * Mirrors the README "Retrying through a NAK" section. Needs the test server (composer nats:start).
 * Run: php examples/retry-via-nak-server-behaviour.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

use function Amp\delay;

/**
 * What Symfony's Worker and retry listener do with a failed message they retry after $delayMs: send its retry
 * copy, then settle the delivery. With retry_via=nak the transport leaves the copy out and NAKs the delivery
 * with that delay. Returns the time of the NAK.
 */
$retryAfter = static function (NatsTransport $transport, Envelope $delivery, int $delayMs, int $retryCount): float {
    $received = asTheWorkerReceivedIt($delivery);
    $transport->send($received->with(new DelayStamp($delayMs), new RedeliveryStamp($retryCount)));
    $transport->ack($received);

    return microtime(true);
};

$lines = [];

// 1. A delay the server cannot hold is cut, and the message is held.
$stream = exampleStream('retry_via_server');
$transport = retryTransport($stream, 'retry_via=nak&ack_wait=1');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('retried in 292 million years')));

    $retryAfter($transport, receiveOne($transport, 5.0)['envelope'], PHP_INT_MAX, 1);
    $later = receiveFor($transport, 2.5);
    $consumer = consumerCounts($stream);

    assertExpected('deliveries while the retry waits, ack_wait run out twice', 0, count($later));
    assertExpected('deliveries that wait for an acknowledgement', 1, $consumer['ackPending']);
    assertExpected('messages in the stream', 1, storedMessages($stream));

    $lines[] = 'a DelayStamp of PHP_INT_MAX was cut to the longest delay the client can send, and the server held the message: not redelivered in 2.5 s with ack_wait 1 s, and still waiting for its acknowledgement';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 2. A retry that waits longer than stream_max_age.
$stream = exampleStream('retry_via_server');
$transport = retryTransport($stream, 'retry_via=nak&stream_max_age=2');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('expires while it waits')));

    $nakAt = $retryAfter($transport, receiveOne($transport, 5.0)['envelope'], 4000, 1);
    delay(3.0);
    $storedAfterMaxAge = storedMessages($stream);
    $later = receiveFor($transport, max(0.0, $nakAt + 5.0 - microtime(true)));

    assertExpected('messages in the stream once stream_max_age has passed', 0, $storedAfterMaxAge);
    assertExpected('deliveries once the 4 s delay has passed', 0, count($later));

    $lines[] = 'a 4 s retry on a stream with stream_max_age 2: the message left the stream before its retry and was never redelivered';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 3. A consumer that has a backoff, set up by a transport without retry_via=nak.
$stream = exampleStream('retry_via_server');
$setup = retryTransport($stream, 'max_deliver=6&backoff[]=1&backoff[]=2&backoff[]=3');
$transport = retryTransport($stream, 'retry_via=nak');
try {
    $setup->setup();
    $transport->send(new Envelope(new ExampleMessage('on a consumer with a backoff')));

    $gaps = [];
    $delivery = receiveOne($transport, 5.0);
    for ($retry = 1; $retry <= 3; $retry++) {
        $nakAt = $retryAfter($transport, $delivery['envelope'], 500, $retry);
        $delivery = receiveOne($transport, 10.0);
        $gaps[] = $delivery['at'] - $nakAt;
    }
    $transport->ack($delivery['envelope']);

    assertSecondsBetween('the first 0.5 s retry (backoff adds 0)', $gaps[0], 0.49, 1.4);
    assertSecondsBetween('the second 0.5 s retry (backoff adds 2 - 1 s)', $gaps[1], 1.49, 3.0);
    assertSecondsBetween('the third 0.5 s retry (backoff adds 3 - 1 s)', $gaps[2], 2.49, 4.0);

    $lines[] = sprintf('on a consumer with backoff 1, 2, 3 s, three retries of 0.5 s came back after %s: NATS added backoff[n] - backoff[0] to each', secondsList($gaps));
} finally {
    $setup->close();
    $transport->close();
    deleteExampleStream($stream);
}

echo "OK retry-via-nak-server-behaviour: the server's limits apply to a retry it holds:\n";
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
