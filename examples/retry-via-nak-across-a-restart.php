<?php

/**
 * Retrying through a NAK, across a restart of the server - a retry that waits while the NATS server restarts is
 * still redelivered, once, when its delay has passed (5.9.0).
 *
 * With `retry_via: nak` a retry that waits is kept by the server, not by the worker: it is a delivery the server
 * holds back. The example NAKs a failed delivery with a 12 s delay and restarts the server with `docker restart`
 * one second later. The worker that sent the NAK is gone by then, as a worker without `reconnect: true` exits
 * when it loses its server. After the restart the consumer still shows the delivery as waiting, and a new worker
 * receives the message once, with retry count 1, about 12 s after the NAK.
 *
 * It can come up to a second early: the server stores the time of a pending delivery in whole seconds, so the
 * time it works from after a restart is rounded down. `ack_wait` (2 s here) plays no part in it.
 *
 * The Worker's part is made by hand, as in examples/retry-via-nak-server-behaviour.php. The stream is on file
 * storage, the default: a memory stream does not survive a restart (examples/reconnect.php).
 *
 * Mirrors the README "Retrying through a NAK" section. Restarts the test server with docker
 * (composer nats:start). Run: php examples/retry-via-nak-across-a-restart.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

if (getenv('NATS_DSN') !== false && getenv('NATS_DSN') !== '') {
    echo "SKIP retry-via-nak-across-a-restart: it restarts the test server nats_test with docker, and NATS_DSN points the examples at another server\n";
    exit(0);
}
requireDockerFor('retry-via-nak-across-a-restart', 'nats_test');

const RETRY_DELAY_SECONDS = 12;

$stream = exampleStream('retry_via_restart');
$query = 'retry_via=nak&ack_wait=2';
$worker = retryTransport($stream, $query);
$newWorker = null;

try {
    $worker->setup();
    $worker->send(new Envelope(new ExampleMessage('retried after the restart')));

    // What Symfony's Worker and retry listener do with a failed message: send its retry copy, with the delay the
    // strategy computed, then settle the delivery, which the transport NAKs with that delay.
    $received = asTheWorkerReceivedIt(receiveOne($worker, 5.0)['envelope']);
    $worker->send($received->with(new DelayStamp(RETRY_DELAY_SECONDS * 1000), new RedeliveryStamp(1)));
    $worker->ack($received);
    $nakAt = microtime(true);
    $worker->close();

    usleep(1_000_000);
    docker('restart', 'nats_test');
    waitUntil('nats_test to answer JetStream requests again', 60, static fn (): bool => withJetStream($stream, answersJetStream(...)));
    $backAt = microtime(true);

    $waiting = consumerCounts($stream)['ackPending'];
    $stored = storedMessages($stream);

    // A new worker, as a supervisor starts one. It pulls until the retry arrives, and a little longer, to see that
    // it arrives once.
    $newWorker = retryTransport($stream, $query);
    $due = max($nakAt + RETRY_DELAY_SECONDS, $backAt);
    $deliveries = [];
    $until = $due + 6.0;
    while (microtime(true) < $until) {
        foreach ($newWorker->get() as $envelope) {
            $deliveries[] = ['after' => microtime(true) - $nakAt, 'retryCount' => RedeliveryStamp::getRetryCountFromEnvelope($envelope)];
            $newWorker->ack($envelope);
            $until = min($until, microtime(true) + 1.5);
        }
    }

    assertExpected('deliveries that wait for an acknowledgement after the restart', 1, $waiting);
    assertExpected('messages in the stream after the restart', 1, $stored);
    assertExpected('deliveries after the restart', 1, count($deliveries));
    assertExpected('the retry count of that delivery', 1, $deliveries[0]['retryCount']);
    // Up to a second early, since the server keeps the time in whole seconds.
    assertSecondsBetween('the redelivery after the NAK', $deliveries[0]['after'], RETRY_DELAY_SECONDS - 1.1, $due - $nakAt + 3.0);

    printf(
        "OK retry-via-nak-across-a-restart: a delivery NAKed with a %d s delay was still waiting after the server restarted (back %.1f s after the NAK), and a new worker received it once, with retry count 1, %.2f s after the NAK\n",
        RETRY_DELAY_SECONDS,
        $backAt - $nakAt,
        $deliveries[0]['after'],
    );
} finally {
    $worker->close();
    $newWorker?->close();
    waitUntil('nats_test to answer JetStream requests again', 60, static fn (): bool => withJetStream($stream, answersJetStream(...)));
    try {
        deleteExampleStream($stream);
    } catch (Throwable) {
        // Nothing to remove when the example failed before it created the stream.
    }
}
