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
$transport = new NatsTransport(
    exampleDsn($stream, 'retry_handler=nats&nak_delay=1&max_deliver=3'),
    ['max_batch_timeout' => 1],
    new PhpSerializer(),
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

    printf(
        "OK nats-retry: the handler failed on attempt 1 and NATS redelivered the message %.1f s later (nak_delay 1 s), where attempt 2 succeeded; Symfony's retry was not published (1 message in the stream), nothing is pending (symfony/messenger %s)\n",
        $waited,
        $messenger,
    );
} finally {
    $transport->close();
    deleteExampleStream($stream);
}
