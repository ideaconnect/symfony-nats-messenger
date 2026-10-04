<?php

/**
 * Connection checks - an idle connection is checked before it is reused, and one that is gone is replaced
 * (5.2.0).
 *
 * A server drops a client that stops answering its pings, which a PHP process does whenever it is outside a
 * transport call, and load balancers drop idle connections too, all without the client noticing until it
 * writes. With `ping_after_idle` the transport checks a connection unused for longer than that with one PING
 * first, and dials again when the server does not answer, so the first message after a quiet period goes out
 * instead of failing. The same check follows an operation that failed on the connection, and a transport
 * whose connection was closed dials a new one on its next operation.
 *
 * This example idles past `ping_after_idle: 1` and sends, then sends again after close() took the connection
 * away, and measures what the check costs: one round trip.
 *
 * Mirrors the README "Losing the Connection" section. Needs the test server (composer nats:start).
 * Run: php examples/connection-checks.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$stream = exampleStream('connection_checks');
$transport = new NatsTransport(exampleDsn($stream), ['ping_after_idle' => 1], new PhpSerializer());

/** Sends one message and returns how long the send took, in milliseconds. */
$timedSend = static function (string $text) use ($transport): float {
    $start = hrtime(true);
    $transport->send(new Envelope(new ExampleMessage($text)));

    return (hrtime(true) - $start) / 1e6;
};

try {
    $transport->setup();
    $warm = $timedSend('while the connection is in use');

    // Idle for longer than ping_after_idle: the next operation checks the connection with a PING first.
    sleep(2);
    $afterIdle = $timedSend('after a quiet period');

    // close() leaves the transport without a connection, as a lost one does: the next operation dials again.
    $transport->close();
    $afterClose = $timedSend('after the connection was closed');

    if ($transport->getMessageCount() !== 3) {
        throw new RuntimeException('expected all three messages to be stored');
    }

    printf("OK connection-checks: sends took %.1f ms in use, %.1f ms after idling (with the PING), %.1f ms after close (with a new dial)\n", $warm, $afterIdle, $afterClose);
} finally {
    $transport->close();
    deleteExampleStream($stream);
}
