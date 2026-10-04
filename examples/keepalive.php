<?php

/**
 * Keepalive - a message handled for longer than ack_wait is not redelivered (5.2.2).
 *
 * `messenger:consume --keepalive` has Symfony call keepalive() from a SIGALRM handler every few seconds. This
 * example wires the same: an alarm every second whose handler calls keepalive() for the message being handled,
 * while the handler works on it for 5 seconds on a consumer with `ack_wait: 2`. Three seconds in, past the
 * ack_wait, a second worker pulls from the same consumer and receives nothing: NATS was told every second that
 * the message is still in progress. Before 5.2.2 the first alarm broke the worker ("Cannot switch fibers in
 * current execution context").
 *
 * The keepalive goes out when the handler next waits on the event loop, as this one does every 100 ms. A
 * handler that only blocks sends it after it returns, so it needs an ack_wait longer than its longest run.
 *
 * Mirrors the README "Long-Running Handlers (--keepalive)" section. Needs the pcntl extension and the test
 * server (composer nats:start). Run: php examples/keepalive.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function Amp\delay;

if (!function_exists('pcntl_signal')) {
    echo "SKIP keepalive: needs the pcntl extension, which Symfony's --keepalive needs as well\n";
    exit(0);
}

$stream = exampleStream('keepalive');
$worker = new NatsTransport(exampleDsn($stream, 'ack_wait=2'), [], new PhpSerializer());
$otherWorker = new NatsTransport(exampleDsn($stream, 'ack_wait=2'), [], new PhpSerializer());

try {
    $worker->setup();
    $worker->send(new Envelope(new ExampleMessage('rebuild the search index')));
    [$envelope] = iterator_to_array($worker->get(), false);

    // What Symfony's console application does for --keepalive=1.
    $keepalives = 0;
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use ($worker, $envelope, &$keepalives): void {
        $worker->keepalive($envelope);
        $keepalives++;
        pcntl_alarm(1);
    });
    pcntl_alarm(1);

    $redelivered = null;
    $end = microtime(true) + 5;
    while (microtime(true) < $end) {
        // The handler's asynchronous work, for example an HTTP call through an amphp client.
        delay(0.1);
        if ($redelivered === null && microtime(true) > $end - 2) {
            $redelivered = count(iterator_to_array($otherWorker->get(), false));
        }
    }
    pcntl_alarm(0);
    pcntl_signal(SIGALRM, SIG_DFL);
    $worker->ack($envelope);

    if ($redelivered !== 0 || $keepalives < 3 || $worker->getMessageCount() !== 0) {
        throw new RuntimeException(sprintf('expected no redelivery after %d keepalives, but the second worker received %d message(s)', $keepalives, (int) $redelivered));
    }

    printf("OK keepalive: handled for 5 s with ack_wait 2 s and %d keepalives, the second worker received nothing\n", $keepalives);
} finally {
    $worker->close();
    $otherWorker->close();
    deleteExampleStream($stream);
}
