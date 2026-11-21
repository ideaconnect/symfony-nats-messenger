<?php

/**
 * NATS retry handler - with `retry_handler: nats`, a message whose handler fails is redelivered by NATS, also with
 * Symfony's retry strategy left at FrameworkBundle's default (5.7.2).
 *
 * Runs Symfony's own Worker and retry listener, as `messenger:consume` does, with the retry strategy FrameworkBundle
 * gives every transport (3 retries), over a message whose handler fails on its first attempt. In nats mode the
 * transport does not publish the copy that Symfony's retry sends: NATS redelivers the original, `nak_delay` after the
 * failure, and `max_deliver` bounds the attempts. Symfony Messenger settles the failed delivery with reject() up to
 * 7.4.21 and 8.1.9, and with ack() from 7.4.22 and 8.1.10 on, since it sent the retry; the transport NAKs it either
 * way. Up to 5.7.1 that ack() reached NATS as an ACK, and the message was gone after its first failure (#90).
 *
 * The transport logs one warning for it, through the logger it was given: Symfony's retry strategy is on although
 * NATS redelivers. It says what to do: set `max_retries: 0`, or, to have Symfony's strategy decide while NATS
 * redelivers, use `retry_handler: symfony` with `retry_via: nak` (examples/retry-via-nak.php).
 *
 * A second case: a message that cannot be decoded is TERMed in nats mode too (5.9.1). The worker that pulls it stops
 * with the decode error, as Symfony's own transports do before Messenger 8.1, and the next worker does not get it.
 * Up to 5.9.0 nats mode NAKed it, and NATS brought it to every new worker, which stopped again, for ever without
 * `max_deliver`.
 *
 * Mirrors the README "Retry Handler Behavior" section. Needs the test server (composer nats:start).
 * Run: php examples/nats-retry.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Composer\InstalledVersions;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

$stream = exampleStream('nats_retry');
$logger = new ExampleLogger();
$transport = new NatsTransport(
    exampleDsn($stream, 'retry_handler=nats&nak_delay=1&max_deliver=3'),
    ['max_batch_timeout' => 1],
    new PhpSerializer(),
    $logger,
);

/** The service locators the retry listener takes, holding the one transport and the one strategy. */
$locator = static fn (object $service): ContainerInterface => new class ($service) implements ContainerInterface {
    public function __construct(private readonly object $service)
    {
    }

    public function get(string $id): object
    {
        return $this->service;
    }

    public function has(string $id): bool
    {
        return true;
    }
};

try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('invoice 7')));

    // The handler fails on the first attempt and succeeds on the next one.
    $attempts = [];
    $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
        ExampleMessage::class => [static function (ExampleMessage $message) use (&$attempts): void {
            $attempts[] = microtime(true);
            if (count($attempts) === 1) {
                throw new RuntimeException('the payment provider did not answer');
            }
        }],
    ]))]);

    // What `messenger:consume` wires up: FrameworkBundle's default retry strategy (3 retries, 1 s, x2) for the
    // transport, and a worker that stops once the message was handled (or after 20 s).
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($locator($transport), $locator(new MultiplierRetryStrategy(3, 1000, 2))));
    $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(20));
    $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$attempts): void {
        if (count($attempts) >= 2) {
            $event->getWorker()->stop();
        }
    });
    (new Worker(['nats' => $transport], $bus, $dispatcher))->run(['sleep' => 100000]);

    $messenger = InstalledVersions::getPrettyVersion('symfony/messenger');
    $consumer = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);
    $stored = storedMessages($stream);
    $waited = count($attempts) >= 2 ? $attempts[1] - $attempts[0] : 0.0;
    $warnings = array_values(array_filter($logger->lines, static fn (string $line): bool => str_starts_with($line, 'warning')));

    if (count($attempts) !== 2 || $waited < 0.9 || $stored !== 1 || ($consumer['num_ack_pending'] ?? null) !== 0 || ($consumer['num_pending'] ?? null) !== 0) {
        throw new RuntimeException(sprintf(
            'expected the handler to run twice, nak_delay apart, with 1 message in the stream and nothing pending; got %d attempt(s), %.1f s apart, %d stored, %s pending acknowledgement, %s pending (symfony/messenger %s)',
            count($attempts),
            $waited,
            $stored,
            var_export($consumer['num_ack_pending'] ?? null, true),
            var_export($consumer['num_pending'] ?? null, true),
            $messenger,
        ));
    }

    // One warning for the whole run, that Symfony's retry strategy is on, which names both ways out.
    $expected = "Symfony's retry strategy retried a message that NATS redelivers itself. Set max_retries: 0 on this transport (Symfony still retries a RecoverableExceptionInterface), or have NATS carry out Symfony's retries with retry_handler: symfony and retry_via: nak";
    if (count($warnings) !== 1 || !str_contains($warnings[0], $expected)) {
        throw new RuntimeException(sprintf('expected one warning that Symfony\'s retry strategy is on, naming retry_via: nak; got: %s', $warnings === [] ? 'none' : implode(' | ', $warnings)));
    }

    printf(
        "OK nats-retry: the handler failed on attempt 1 and NATS redelivered the message %.1f s later (nak_delay 1 s), where attempt 2 succeeded; Symfony's retry was not published (1 message in the stream), nothing is pending (symfony/messenger %s); the transport logged one warning:\n    %s\n",
        $waited,
        $messenger,
        $warnings[0],
    );
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// A message that cannot be decoded, in nats mode.
$stream = exampleStream('nats_retry_undecodable');
$natsMode = static fn (): NatsTransport => new NatsTransport(exampleDsn($stream, 'retry_handler=nats'), ['max_batch_timeout' => 0.2], new IDCT\NatsMessenger\Serializer\IgbinarySerializer());
$setUp = $natsMode();
try {
    $setUp->setup();
    // igbinary, but no envelope: what another application's publisher could put on the subject.
    withJetStream($stream, static fn (JetStreamContext $jetStream) => $jetStream->publish($stream . '.messages', (string) igbinary_serialize('not an envelope'))->await());

    $stops = [];
    foreach ([1, 2] as $start) {
        // A new transport object for each start, as a supervisor starts a new worker.
        $worker = $natsMode();
        try {
            $deadline = microtime(true) + 1.5;
            while (microtime(true) < $deadline) {
                foreach ($worker->get() as $envelope) {
                    $worker->ack($envelope);
                }
            }
            $stops[] = 'ran';
        } catch (Symfony\Component\Messenger\Exception\MessageDecodingFailedException) {
            $stops[] = 'stopped on the decode error';
        } finally {
            $worker->close();
        }
    }

    $consumer = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);
    $deliveries = is_array($consumer['delivered'] ?? null) ? ($consumer['delivered']['consumer_seq'] ?? null) : null;
    if ($stops !== ['stopped on the decode error', 'ran'] || $deliveries !== 1 || ($consumer['num_ack_pending'] ?? null) !== 0 || ($consumer['num_pending'] ?? null) !== 0) {
        throw new RuntimeException(sprintf('expected the first worker to stop on the message and the second to run, with one delivery and nothing left; got %s, %s deliveries', json_encode($stops), json_encode($deliveries)));
    }

    echo "OK nats-retry: a message that cannot be decoded stopped the first worker and was TERMed: the second worker ran, the message was delivered once, and nothing is pending\n";
} finally {
    $setUp->close();
    deleteExampleStream($stream);
}
