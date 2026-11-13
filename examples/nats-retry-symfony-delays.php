<?php

/**
 * NATS retry handler with Symfony's delays - with `retry_handler: nats` and `retry_delay_source: symfony`, NATS
 * redelivers a failed message after the delay Symfony's retry strategy computed, and a message whose retries are
 * used up stays in the stream: no failure transport, the MAX_DELIVERIES advisory instead.
 *
 * Runs Symfony's own Worker and retry listener, as `messenger:consume` does, and measures when the handler runs
 * again and when NATS publishes its advisory.
 *
 * Needs the test server (composer nats:start). Run: php examples/nats-retry-symfony-delays.php
 * LONG_DELAY_WINDOW=180 sets how long the 2 h delay is watched (default 20 s); NATS_CONTAINER names a docker
 * container to restart halfway through that window.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;

/** Passes everything on to the transport and notes when each failed delivery was settled. */
final class SettleTimes implements TransportInterface
{
    /** @var list<float> */
    public array $settledAt = [];

    public function __construct(private readonly NatsTransport $transport)
    {
    }

    public function get(): iterable
    {
        return $this->transport->get();
    }

    public function ack(Envelope $envelope): void
    {
        $this->transport->ack($envelope);
        $this->settledAt[] = microtime(true);
    }

    public function reject(Envelope $envelope): void
    {
        $this->transport->reject($envelope);
        $this->settledAt[] = microtime(true);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->transport->send($envelope);
    }
}

/**
 * Sends one message and runs the worker over it until the handler has not run for $idle seconds. The handler
 * fails with what $failure(attempt) returns, and succeeds when that is null.
 *
 * @param \Closure(int): ?\Throwable $failure
 * @param (\Closure(string): void)|null $before Runs after setup and send, before the worker starts.
 * @return array{gaps: list<float>, delays: list<float>, attempts: int, settledAt: list<float>, advisories: list<array{at: float, kind: string, deliveries: int|null}>, consumer: array<string, mixed>, stored: int, log: ExampleLogger, stream: string}
 */
function runFailing(string $query, MultiplierRetryStrategy $strategy, \Closure $failure, float $idle, ?\Closure $before = null, bool $keep = false): array
{
    $stream = exampleStream('symfony_delays');
    $logger = new ExampleLogger();
    $nats = new NatsTransport(exampleDsn($stream, $query), ['max_batch_timeout' => 0.2], new PhpSerializer(), $logger);
    $transport = new SettleTimes($nats);
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

    // The advisories NATS publishes about this consumer, on a connection of their own.
    $advisories = [];
    $listener = (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream))->client;
    $listener->connect()->await();
    $listener->subscribe('$JS.EVENT.ADVISORY.CONSUMER.*.' . $stream . '.client', static function (NatsMessage $message) use (&$advisories): void {
        $event = json_decode($message->payload, true);
        $advisories[] = [
            'at' => microtime(true),
            'kind' => explode('.', $message->subject)[4] ?? '?',
            'deliveries' => is_array($event) && is_int($event['deliveries'] ?? null) ? $event['deliveries'] : null,
        ];
    })->await();

    try {
        $nats->setup();
        $nats->send(new Envelope(new ExampleMessage('invoice 7')));
        if ($before !== null) {
            $before($stream);
        }

        $attempts = [];
        $delays = [];
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExampleMessage::class => [static function () use (&$attempts, $failure): void {
                $attempts[] = microtime(true);
                $error = $failure(count($attempts));
                if ($error !== null) {
                    throw $error;
                }
            }],
        ]))]);

        $handled = false;
        $dispatcher = new EventDispatcher();
        // FrameworkBundle registers both listeners; the first names the failure on the envelope.
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($locator($transport), $locator($strategy), null, $dispatcher));
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(120));
        $dispatcher->addListener(WorkerMessageRetriedEvent::class, static function (WorkerMessageRetriedEvent $event) use (&$delays): void {
            $delays[] = ($event->getEnvelope()->last(DelayStamp::class)?->getDelay() ?? 0) / 1000.0;
        });
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$attempts, $idle, $listener): void {
            // The client reads its socket only while one of its operations waits, so a PING lets the advisories in.
            $listener->rtt()->await();
            if ($attempts !== [] && microtime(true) - $attempts[count($attempts) - 1] > $idle) {
                $event->getWorker()->stop();
            }
        });
        (new Worker(['nats' => $transport], $bus, $dispatcher))->run(['sleep' => 20000]);

        $listener->rtt()->await();

        $gaps = [];
        for ($i = 1, $n = count($attempts); $i < $n; $i++) {
            $gaps[] = $attempts[$i] - $attempts[$i - 1];
        }

        /** @var array<string, mixed> $consumer */
        $consumer = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);

        return [
            'gaps' => $gaps, 'delays' => $delays, 'attempts' => count($attempts), 'settledAt' => $transport->settledAt,
            'advisories' => $advisories, 'consumer' => $consumer, 'stored' => storedMessages($stream), 'log' => $logger, 'stream' => $stream,
        ];
    } finally {
        $nats->close();
        $listener->disconnect()->await();
        if (!$keep) {
            deleteExampleStream($stream);
        }
    }
}

/** @param list<float> $values */
function seconds(array $values): string
{
    return $values === [] ? '-' : implode(', ', array_map(static fn (float $value): string => sprintf('%.2f s', $value), $values));
}

/**
 * @param array{advisories: list<array{at: float, kind: string, deliveries: int|null}>, settledAt: list<float>} $run
 */
function advisories(array $run): string
{
    $last = $run['settledAt'] === [] ? 0.0 : $run['settledAt'][count($run['settledAt']) - 1];

    return implode(', ', array_map(
        static fn (array $advisory): string => sprintf('%s (deliveries %s) %.0f ms after the last NAK/TERM', $advisory['kind'], $advisory['deliveries'] ?? '-', ($advisory['at'] - $last) * 1000),
        array_values(array_filter($run['advisories'], static fn (array $advisory): bool => in_array($advisory['kind'], ['MAX_DELIVERIES', 'MSG_TERMINATED'], true))),
    )) ?: 'no advisory';
}

/**
 * @param array{stored: int, consumer: array<string, mixed>} $run
 */
function state(array $run): string
{
    return sprintf('stream msgs %d, ack pending %s, pending %s', $run['stored'], var_export($run['consumer']['num_ack_pending'] ?? null, true), var_export($run['consumer']['num_pending'] ?? null, true));
}

function expect(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException('expected ' . $what);
    }
}

/**
 * @param list<float> $gaps
 * @param list<float> $delays
 */
function gapsFollow(array $gaps, array $delays): bool
{
    if (count($gaps) !== count($delays)) {
        return false;
    }

    foreach ($gaps as $i => $gap) {
        if ($gap < $delays[$i] - 0.05 || $gap > $delays[$i] + 0.4) {
            return false;
        }
    }

    return true;
}

$always = static fn (): \Throwable => new RuntimeException('the payment provider did not answer');
$source = 'retry_handler=nats&retry_delay_source=symfony';

// 1. The whole flow: max_retries 3, max_deliver 4. Three NAKs with Symfony's delay (multiplier and jitter), then a
//    plain NAK of delivery 4, after which NATS keeps the message and publishes MAX_DELIVERIES.
$run = runFailing($source . '&max_deliver=4&nak_delay=9', new MultiplierRetryStrategy(3, 1000, 2, 0, 0.2), $always, 6.0);
printf("1. max_retries 3, max_deliver 4, 1 s x2, jitter 0.2: %d handler runs; Symfony computed %s; NATS redelivered after %s\n   then: %s; %s\n", $run['attempts'], seconds($run['delays']), seconds($run['gaps']), advisories($run), state($run));
$max = array_values(array_filter($run['advisories'], static fn (array $advisory): bool => $advisory['kind'] === 'MAX_DELIVERIES'));
expect($run['attempts'] === 4 && gapsFollow($run['gaps'], $run['delays']) && count($run['delays']) === 3, 'four handler runs, each redelivery at the delay Symfony computed');
expect(count($max) === 1 && $max[0]['deliveries'] === 4, 'one MAX_DELIVERIES advisory with deliveries 4');
expect($run['stored'] === 1 && ($run['consumer']['num_ack_pending'] ?? null) === 0 && ($run['consumer']['num_pending'] ?? null) === 0, 'the message still stored and nothing pending');
expect(!$run['log']->logged('retry_'), 'no warning');

// 2. The delay a recoverable exception carries replaces the strategy's.
$run = runFailing($source . '&max_deliver=4', new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0), static fn (int $attempt): ?\Throwable => $attempt === 1 ? new RecoverableMessageHandlingException('try again in 2.5 s', retryDelay: 2500) : null, 3.5);
printf("2. RecoverableMessageHandlingException(retryDelay: 2500): Symfony computed %s; NATS redelivered after %s; handled on run %d; %s\n", seconds($run['delays']), seconds($run['gaps']), $run['attempts'], state($run));
expect($run['attempts'] === 2 && gapsFollow($run['gaps'], [2.5]), 'a redelivery after 2.5 s');

// 3. An unrecoverable failure on the first delivery, which Symfony does not retry.
$never = static fn (): \Throwable => new UnrecoverableMessageHandlingException('this invoice does not exist');
foreach (['nak' => '', 'term' => '', 'term, work-queue stream' => '&stream_retention=workqueue', 'nak, work-queue stream' => '&stream_retention=workqueue'] as $case => $extra) {
    $action = explode(',', $case)[0];
    $run = runFailing($source . '&max_deliver=4&no_retry_action=' . $action . $extra, new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0), $never, 2.0);
    printf("3. unrecoverable, no_retry_action=%s: %d handler run(s), %s apart; %s; %s; warning: %s\n", $case, $run['attempts'], seconds($run['gaps']), advisories($run), state($run), $run['log']->logged('does not retry') ? 'yes' : 'no');
    expect($run['attempts'] === ($action === 'nak' ? 4 : 1), 'four handler runs with nak, one with term');
}

// 4. max_deliver not at max_retries + 1.
$run = runFailing($source . '&max_deliver=4', new MultiplierRetryStrategy(1, 1000, 2, 0, 0.0), $always, 3.0);
printf("4a. max_retries 1, max_deliver 4 (too high): %d handler runs, %s apart; %s; %s; warning: %s\n", $run['attempts'], seconds($run['gaps']), advisories($run), state($run), $run['log']->logged('does not retry a message after delivery 2') ? 'yes' : 'no');
expect($run['attempts'] === 4 && $run['log']->logged('does not retry a message after delivery 2'), 'four handler runs and the warning');
$run = runFailing($source . '&max_deliver=2', new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0), $always, 3.0);
printf("4b. max_retries 3, max_deliver 2 (too low): %d handler runs, %s apart; %s; %s; warning: %s\n", $run['attempts'], seconds($run['gaps']), advisories($run), state($run), $run['log']->logged('max_deliver is 2') ? 'yes' : 'no');
expect($run['attempts'] === 2 && $run['log']->logged('max_deliver is 2'), 'two handler runs and the warning');

// 5. A delivery nobody settled (a worker that crashed, or ack_wait running out) counts as a retry: the first
//    delivery is pulled and dropped here, and the worker's first failure is then Symfony's retry 2 (2 s, not 1 s).
$dropped = 0.0;
$run = runFailing($source . '&max_deliver=4&ack_wait=1', new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0), static fn (int $attempt): ?\Throwable => $attempt === 1 ? $always() : null, 3.0, static function (string $stream) use (&$dropped): void {
    withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->fetchBatch($stream, 'client', 1, 1000)->await());
    $dropped = microtime(true);
});
printf("5. first delivery dropped unsettled (ack_wait 1 s): the worker's first failure got Symfony's delay %s (retry 2 of 1 s x2); redelivered after %s; handled on worker run %d; %s\n", seconds($run['delays']), seconds($run['gaps']), $run['attempts'], state($run));
expect($run['delays'] === [2.0] && gapsFollow($run['gaps'], [2.0]), "Symfony's second delay, 2 s");

// 6. A long delay: 2 h. The message is not redelivered early, ack_wait (5 s) does not bring it back, and the
//    consumer holds it as one pending acknowledgement.
$window = (int) (getenv('LONG_DELAY_WINDOW') ?: 20);
$container = getenv('NATS_CONTAINER') ?: null;
$run = runFailing($source . '&max_deliver=4&ack_wait=5', new MultiplierRetryStrategy(3, 7_200_000, 1, 0, 0.0), $always, 2.0, null, true);
$stream = $run['stream'];
try {
    printf("6. delay 2 h, ack_wait 5 s: Symfony computed %s; after the NAK: %s\n", seconds($run['delays']), state($run));
    $started = microtime(true);
    $restarted = false;
    while (microtime(true) - $started < $window) {
        \Amp\delay(min(15.0, $window / 4));
        if ($container !== null && !$restarted && microtime(true) - $started > $window / 2) {
            docker('restart', $container);
            waitUntil('the server to answer again', 30, static function () use ($stream): bool {
                try {
                    return storedMessages($stream) >= 0;
                } catch (\Throwable) {
                    return false;
                }
            });
            $restarted = true;
            printf("   +%3.0f s: restarted the server (%s)\n", microtime(true) - $started, $container);
        }
        /** @var array<string, mixed> $consumer */
        $consumer = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);
        printf("   +%3.0f s: ack pending %s, redelivered %s, pending %s, delivered %s\n", microtime(true) - $started, var_export($consumer['num_ack_pending'] ?? null, true), var_export($consumer['num_redelivered'] ?? null, true), var_export($consumer['num_pending'] ?? null, true), json_encode($consumer['delivered'] ?? null));
        expect(($consumer['num_ack_pending'] ?? null) === 1, 'the message held as one pending acknowledgement');
    }
    $early = withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): int {
        try {
            return count($jetStream->fetchBatch($stream, 'client', 1, 2000)->await());
        } catch (\Throwable) {
            return 0;
        }
    });
    printf("   a pull after %d s got %d message(s)%s; %d handler run(s) in all\n", $window, $early, $restarted ? ', the server restart included' : '', $run['attempts']);
    expect($early === 0 && $run['attempts'] === 1, 'no redelivery inside the window');
} finally {
    deleteExampleStream($stream);
}

echo "OK nats-retry-symfony-delays\n";
