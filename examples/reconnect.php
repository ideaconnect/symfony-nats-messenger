<?php

/**
 * Reconnect - with `reconnect: true` a worker polls through a restart of its server without exiting, and
 * `auto_setup` provisions the stream again once the client reconnected (5.7.0).
 *
 * The worker consumes from a memory stream, which does not survive a restart of the server. While one of its
 * pulls is in flight, the server is restarted with `docker restart`: the server ends the pull with "409 Server
 * Shutdown", which is an empty batch, then closes the connection, and the client recovers it in the background
 * while the worker's get() calls end empty. Once the server is back, a message sent through the same transport
 * goes out on the recovered connection: the transport saw the client's reconnect count move, so `auto_setup`
 * verified the provisioning again and recreated the stream the restart lost. The worker receives that message.
 * The client reports the loss and the recovery through the logger the transport was given.
 *
 * Before 5.7.0 the 409 read as a missing consumer, `auto_setup` ran setup() against the server that was going
 * away, and the worker exited with "Failed to setup NATS stream". With reconnect off (the default) the get()
 * that runs into the lost connection fails and `messenger:consume` exits; its supervisor starts a new worker.
 *
 * Mirrors the README "Automatic Reconnect" and "Logging" sections. Restarts the test server with docker
 * (composer nats:start). Run: php examples/reconnect.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

if (getenv('NATS_DSN') !== false && getenv('NATS_DSN') !== '') {
    echo "SKIP reconnect: it restarts the test server nats_test with docker, and NATS_DSN points the examples at another server\n";
    exit(0);
}
requireDockerFor('reconnect', 'nats_test');

$stream = exampleStream('reconnect');
$logger = new ExampleLogger();
$worker = new NatsTransport(
    exampleDsn($stream),
    ['reconnect' => true, 'auto_setup' => true, 'stream_storage' => 'memory', 'max_batch_timeout' => 2],
    new PhpSerializer(),
    $logger,
);

/** One pull, as messenger:consume makes it; acknowledges what arrived and returns the texts. */
$pull = static function () use ($worker): array {
    $texts = [];
    foreach ($worker->get() as $envelope) {
        $message = $envelope->getMessage();
        assert($message instanceof ExampleMessage);
        $texts[] = $message->text;
        $worker->ack($envelope);
    }

    return $texts;
};

$restart = null;
try {
    $worker->send(new Envelope(new ExampleMessage('before the restart')));
    $before = $pull();

    // The restart runs next to the worker, so that the server goes away in the middle of the next pull.
    $restart = proc_open(['docker', 'restart', 'nats_test'], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($restart === false) {
        throw new RuntimeException('could not run docker restart nats_test');
    }
    $start = microtime(true);
    $inFlight = $pull();
    $inFlightSeconds = microtime(true) - $start;

    // The worker goes on polling until the restart is over; none of these pulls may throw.
    $emptyPulls = 0;
    while (proc_get_status($restart)['running']) {
        $emptyPulls += $pull() === [] ? 1 : 0;
    }
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($restart) !== 0) {
        throw new RuntimeException('docker restart nats_test failed: ' . $error);
    }
    $restart = null;

    // The first send once the server is back recreates the memory stream before it publishes.
    $worker->send(new Envelope(new ExampleMessage('after the restart')));
    $after = [];
    $deadline = microtime(true) + 30;
    while ($after === [] && microtime(true) < $deadline) {
        $after = $pull();
    }

    if ($before !== ['before the restart'] || $inFlight !== [] || $after !== ['after the restart'] || !$logger->logged('Reconnected')) {
        throw new RuntimeException(sprintf('expected the message before, an empty pull at the stop, the message after and a logged reconnect; got [%s], [%s], [%s], log: %s', implode(', ', $before), implode(', ', $inFlight), implode(', ', $after), implode(' | ', $logger->lines)));
    }

    printf(
        "OK reconnect: the pull in flight at the restart ended empty after %.2f s and %d more until the restart was over, none threw; the message sent after it recreated the memory stream and was received; the client logged %d lines:\n",
        $inFlightSeconds,
        $emptyPulls,
        count($logger->lines),
    );
    $logger->printLines();
} finally {
    if (is_resource($restart)) {
        proc_close($restart);
    }
    $worker->close();
    waitUntil('nats_test to answer JetStream requests again', 60, static fn (): bool => withJetStream($stream, answersJetStream(...)));
    try {
        deleteExampleStream($stream);
    } catch (Throwable) {
        // A memory stream the example failed before recreating went with the restart.
    }
}
