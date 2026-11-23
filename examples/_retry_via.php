<?php

/**
 * Shared by the retry_via examples: Symfony's own Worker with its retry and failure-transport listeners, as
 * `messenger:consume` wires them, a record of what one run of it did, and a few ways to look at the stream's
 * consumer. Not an example itself (the runner skips files starting with "_").
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * A transport of an example, with Symfony's PhpSerializer and short pulls, so that a worker notices a redelivery
 * within 0.2 s.
 */
function retryTransport(string $stream, string $query, ?ExampleLogger $logger = null): NatsTransport
{
    return new NatsTransport(exampleDsn($stream, $query), ['max_batch_timeout' => 0.2], new PhpSerializer(), $logger);
}

/** A service locator that holds one service, as the retry and failure-transport listeners take theirs. */
function locatorOf(object $service): ContainerInterface
{
    return new class ($service) implements ContainerInterface {
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
}

/**
 * What Symfony's Worker did in one run: every message it received, with the retry count the transport gave it,
 * what the retry strategy decided on every failure, what the handler finished, and the failure transport.
 */
final class WorkerRun
{
    /** @var list<array{at: float, retryCount: int, text: string}> each message the worker received, in order */
    public array $received = [];

    /** @var list<bool> willRetry() of each failure, in order */
    public array $willRetry = [];

    /** @var list<class-string<\Throwable>> the class of what each failure threw, in order */
    public array $failedWith = [];

    /** @var list<array{at: float, text: string}> each message the handler finished, in order */
    public array $handled = [];

    /** The failure transport, which gets a copy of a message Symfony gives up on. */
    public InMemoryTransport $failureTransport;

    public function __construct()
    {
        $this->failureTransport = new InMemoryTransport();
    }

    /** @return list<float> the seconds between each time the worker received a message and the next */
    public function gaps(): array
    {
        $gaps = [];
        for ($i = 1; $i < count($this->received); $i++) {
            $gaps[] = $this->received[$i]['at'] - $this->received[$i - 1]['at'];
        }

        return $gaps;
    }

    /** @return list<int> the retry count of each message the worker received, in order */
    public function retryCounts(): array
    {
        return array_map(static fn (array $received): int => $received['retryCount'], $this->received);
    }

    /** How many copies the failure transport got. */
    public function failureCopies(): int
    {
        return count($this->failureTransport->getSent());
    }

    /** When the handler finished the message with this text; null when it never did. */
    public function handledAt(string $text): ?float
    {
        foreach ($this->handled as $handled) {
            if ($handled['text'] === $text) {
                return $handled['at'];
            }
        }

        return null;
    }
}

/**
 * Runs Symfony's Worker over the transport with its retry listener and failure-transport listener, until $done
 * says so, and then for $quiet seconds more, to see whether anything else arrives. Gives up after $seconds.
 *
 * @param \Closure(ExampleMessage, WorkerRun): void $handler Throws to fail the message
 * @param \Closure(WorkerRun): bool                 $done
 */
function runWorker(NatsTransport $transport, RetryStrategyInterface $strategy, \Closure $handler, \Closure $done, float $quiet = 0.0, float $seconds = 20.0): WorkerRun
{
    $run = new WorkerRun();
    $middleware = [new HandleMessageMiddleware(new HandlersLocator([
        ExampleMessage::class => [static function (ExampleMessage $message) use ($handler, $run): void {
            $handler($message, $run);
            $run->handled[] = ['at' => microtime(true), 'text' => $message->text];
        }],
    ]))];
    if (class_exists(DecodeFailedMessageMiddleware::class)) {
        // FrameworkBundle 8.1 puts it in every bus: it decodes a message that could not be decoded once more, with the
        // transport's serializer, and fails it.
        array_unshift($middleware, new DecodeFailedMessageMiddleware(locatorOf((new ReflectionProperty(NatsTransport::class, 'serializer'))->getValue($transport))));
    }
    $bus = new MessageBus($middleware);

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(locatorOf($transport), locatorOf($strategy)));
    $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(locatorOf($run->failureTransport)));
    $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event) use ($run): void {
        $message = $event->getEnvelope()->getMessage();
        $run->received[] = [
            'at' => microtime(true),
            'retryCount' => RedeliveryStamp::getRetryCountFromEnvelope($event->getEnvelope()),
            'text' => $message instanceof ExampleMessage ? $message->text : $message::class,
        ];
    });
    $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($run): void {
        $run->failedWith[] = $event->getThrowable()::class;
    });
    // After the retry listener, which is the one that decides.
    $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($run): void {
        $run->willRetry[] = $event->willRetry();
    }, -1000);

    $deadline = microtime(true) + $seconds;
    $doneAt = null;
    $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($run, $done, $quiet, $deadline, &$doneAt): void {
        if ($doneAt === null && $done($run)) {
            $doneAt = microtime(true);
        }
        if (($doneAt !== null && microtime(true) - $doneAt >= $quiet) || microtime(true) > $deadline) {
            $event->getWorker()->stop();
        }
    });

    (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

    return $run;
}

/**
 * Receives through the transport for $seconds, as a worker's pulls do, without a handler.
 *
 * @return list<array{at: float, envelope: Envelope}> what arrived, each one acknowledged unless $ack is false
 */
function receiveFor(NatsTransport $transport, float $seconds, bool $ack = true): array
{
    $received = [];
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        foreach ($transport->get() as $envelope) {
            $received[] = ['at' => microtime(true), 'envelope' => $envelope];
            if ($ack) {
                $transport->ack($envelope);
            }
        }
    }

    return $received;
}

/**
 * Receives through the transport until one message arrives; fails when none does within $seconds. The message is
 * not settled.
 *
 * @return array{at: float, envelope: Envelope}
 */
function receiveOne(NatsTransport $transport, float $seconds): array
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        foreach ($transport->get() as $envelope) {
            return ['at' => microtime(true), 'envelope' => $envelope];
        }
    }

    throw new RuntimeException(sprintf('no message arrived within %.1f s', $seconds));
}

/**
 * The envelope as Symfony's Worker hands it to the bus: with the ReceivedStamp that tells the transport the
 * message was received in this process. The examples that settle a delivery by hand add it themselves.
 */
function asTheWorkerReceivedIt(Envelope $envelope): Envelope
{
    return $envelope->with(new ReceivedStamp('async'));
}

/**
 * Starts listening for the advisories NATS publishes about the consumers of a stream (MSG_TERMINATED for a TERM,
 * MAX_DELIVERIES when max_deliver ends the deliveries). The closure it returns stops listening and gives how
 * many came, by kind.
 *
 * @return \Closure(): array<string, int>
 */
function consumerAdvisories(string $stream): \Closure
{
    $client = (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream))->client;
    $client->connect()->await();
    $queue = $client->subscribeQueue('$JS.EVENT.ADVISORY.CONSUMER.*.' . $stream . '.*')->await();
    $client->flush()->await();

    return static function () use ($client, $queue): array {
        $kinds = [];
        $queue->setTimeout(0.5);
        while (($message = $queue->next()) !== null) {
            $kinds[] = explode('.', $message->subject)[4] ?? '?';
        }
        $client->disconnect()->await();

        $counts = array_count_values($kinds);
        ksort($counts);

        return $counts;
    };
}

/**
 * What the server reports about a consumer: how many of its deliveries wait for an acknowledgement, a NAK retry
 * that waits for its delay among them, and how many messages it has not delivered yet.
 *
 * @return array{ackPending: int, pending: int}
 */
function consumerCounts(string $stream, string $consumer = 'client'): array
{
    return withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream, $consumer): array {
        $raw = $jetStream->getConsumer($stream, $consumer)->await()->raw;

        return [
            'ackPending' => is_int($raw['num_ack_pending'] ?? null) ? $raw['num_ack_pending'] : -1,
            'pending' => is_int($raw['num_pending'] ?? null) ? $raw['num_pending'] : -1,
        ];
    });
}

/**
 * The warnings the transport and the NATS client logged, without the client's own lines about its connection.
 *
 * @return list<string>
 */
function warningsLogged(ExampleLogger $logger): array
{
    return array_values(array_filter($logger->lines, static fn (string $line): bool => str_starts_with($line, 'warning')));
}

/**
 * The seconds as "0.20, 0.40 and 0.50 s", for an example's OK line.
 *
 * @param list<float> $seconds
 */
function secondsList(array $seconds): string
{
    $parts = array_map(static fn (float $s): string => sprintf('%.2f', $s), $seconds);
    $last = array_pop($parts);

    return ($parts === [] ? '' : implode(', ', $parts) . ' and ') . $last . ' s';
}

/**
 * Fails with $what unless $actual is at least $atLeast and below $below, both in seconds.
 */
function assertSecondsBetween(string $what, float $actual, float $atLeast, float $below): void
{
    if ($actual < $atLeast || $actual >= $below) {
        throw new RuntimeException(sprintf('%s: expected %.2f s or more and less than %.2f s, got %.2f s', $what, $atLeast, $below, $actual));
    }
}

/**
 * Fails with what was expected and what came unless the two are the same.
 */
function assertExpected(string $what, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: expected %s, got %s', $what, json_encode($expected), json_encode($actual)));
    }
}
