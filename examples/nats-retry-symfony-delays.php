<?php

/**
 * NATS retry handler with Symfony's delays - with `retry_handler: nats` and `retry_delay_source: symfony`, NATS
 * redelivers a failed message after the delay Symfony's retry strategy computed for that failure.
 *
 * Runs Symfony's own Worker and retry listener, as `messenger:consume` does, and measures when the handler runs
 * again: the multiplier (1 s, 2 s, 4 s), the jitter, the delay a RecoverableMessageHandlingException carries, the
 * TERM once max_retries is used up, and what a consumer `backoff` adds to a delayed NAK.
 *
 * Needs the test server (composer nats:start). Run: php examples/nats-retry-symfony-delays.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Sends one message and runs the worker over it. The handler fails with $failure() on each of its first $failures
 * attempts. Returns the gaps between attempts in seconds, the delays Symfony computed in seconds, and the consumer.
 *
 * @param \Closure(int): \Throwable $failure
 * @return array{gaps: list<float>, delays: list<float>, attempts: int, consumer: array<string, mixed>, log: ExampleLogger}
 */
function runFailing(string $query, MultiplierRetryStrategy $strategy, int $failures, \Closure $failure, float $settle = 0.0): array
{
    $stream = exampleStream('symfony_delays');
    $logger = new ExampleLogger();
    $transport = new NatsTransport(exampleDsn($stream, $query), ['max_batch_timeout' => 0.2], new PhpSerializer(), $logger);
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

        $attempts = [];
        $delays = [];
        $doneAt = null;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExampleMessage::class => [static function () use (&$attempts, $failures, $failure): void {
                $attempts[] = microtime(true);
                if (count($attempts) <= $failures) {
                    throw $failure(count($attempts));
                }
            }],
        ]))]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($locator($transport), $locator($strategy), null, $dispatcher));
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(60));
        $dispatcher->addListener(WorkerMessageRetriedEvent::class, static function (WorkerMessageRetriedEvent $event) use (&$delays): void {
            $delays[] = ($event->getEnvelope()->last(DelayStamp::class)?->getDelay() ?? 0) / 1000.0;
        });
        // Stops once the handler has run $failures + 1 times, or, when Symfony gave up before that, $settle seconds
        // after the last attempt, which shows that nothing came back.
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$attempts, &$delays, $failures, $settle): void {
            $last = $attempts === [] ? null : $attempts[count($attempts) - 1];
            $gaveUp = $settle > 0.0 && $last !== null && count($delays) < count($attempts) && microtime(true) - $last > $settle;
            if (count($attempts) > $failures || $gaveUp) {
                $event->getWorker()->stop();
            }
        });
        (new Worker(['nats' => $transport], $bus, $dispatcher))->run(['sleep' => 20000]);

        $gaps = [];
        for ($i = 1, $n = count($attempts); $i < $n; $i++) {
            $gaps[] = $attempts[$i] - $attempts[$i - 1];
        }

        /** @var array<string, mixed> $consumer */
        $consumer = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);

        return ['gaps' => $gaps, 'delays' => $delays, 'attempts' => count($attempts), 'consumer' => $consumer, 'log' => $logger];
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }
}

/** @param list<float> $values */
function seconds(array $values): string
{
    return $values === [] ? '-' : implode(', ', array_map(static fn (float $value): string => sprintf('%.2f s', $value), $values));
}

/**
 * Each gap must be the delay Symfony computed for it plus $extra seconds, within 0.4 s.
 *
 * @param list<float> $gaps
 * @param list<float> $delays
 * @param list<float> $extra
 */
function assertGapsFollow(string $case, array $gaps, array $delays, array $extra = []): void
{
    if (count($gaps) !== count($delays)) {
        throw new RuntimeException(sprintf('%s: %d redeliveries for %d retries Symfony computed a delay for', $case, count($gaps), count($delays)));
    }

    foreach ($gaps as $i => $gap) {
        $expected = $delays[$i] + ($extra[$i] ?? 0.0);
        if ($gap < $expected - 0.05 || $gap > $expected + 0.4) {
            throw new RuntimeException(sprintf('%s: redelivery %d came after %.2f s, expected %.2f s', $case, $i + 1, $gap, $expected));
        }
    }
}

$always = static fn (): \Throwable => new RuntimeException('the payment provider did not answer');
$source = 'retry_handler=nats&retry_delay_source=symfony';

// 1. The multiplier: 1 s, 2 s, 4 s, where nak_delay (here 9 s) plays no part.
$run = runFailing($source . '&nak_delay=9', new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0), 3, $always);
assertGapsFollow('multiplier', $run['gaps'], [1.0, 2.0, 4.0]);
if ($run['delays'] !== [1.0, 2.0, 4.0] || $run['log']->logged('Symfony\'s retry strategy retried')) {
    throw new RuntimeException('multiplier: expected Symfony to compute 1, 2 and 4 s and no warning; got ' . seconds($run['delays']));
}
printf("OK multiplier (1 s x2, jitter 0): Symfony computed %s; NATS redelivered after %s\n", seconds($run['delays']), seconds($run['gaps']));

// 2. Jitter: the delays are spread, and each redelivery follows the delay computed for it.
$run = runFailing($source, new MultiplierRetryStrategy(3, 2000, 1, 0, 0.5), 3, $always);
assertGapsFollow('jitter', $run['gaps'], $run['delays']);
if (count(array_unique($run['delays'])) < 2 || min($run['delays']) < 1.0 || max($run['delays']) > 3.0) {
    throw new RuntimeException('jitter: expected three different delays between 1 and 3 s; got ' . seconds($run['delays']));
}
printf("OK jitter (2 s, jitter 0.5): Symfony computed %s; NATS redelivered after %s\n", seconds($run['delays']), seconds($run['gaps']));

// 3. The delay a recoverable exception carries replaces the strategy's.
$run = runFailing($source, new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0), 1, static fn (): \Throwable => new RecoverableMessageHandlingException('try again in 2.5 s', retryDelay: 2500));
assertGapsFollow('recoverable', $run['gaps'], [2.5]);
printf("OK RecoverableMessageHandlingException(retryDelay: 2500): Symfony computed %s; NATS redelivered after %s\n", seconds($run['delays']), seconds($run['gaps']));

// 4. max_retries used up: the third delivery is TERMed, nothing comes back, nothing stays pending.
$run = runFailing($source, new MultiplierRetryStrategy(2, 500, 1, 0, 0.0), 99, $always, 3.0);
if ($run['attempts'] !== 3 || ($run['consumer']['num_ack_pending'] ?? null) !== 0 || ($run['consumer']['num_pending'] ?? null) !== 0) {
    throw new RuntimeException(sprintf('max_retries: expected 3 attempts and nothing pending; got %d attempt(s), %s pending acknowledgement', $run['attempts'], var_export($run['consumer']['num_ack_pending'] ?? null, true)));
}
printf("OK max_retries 2: the handler ran %d times (after %s), the last delivery was TERMed and nothing came back within 3 s; nothing is pending\n", $run['attempts'], seconds($run['gaps']));

// 5. With a consumer backoff, the server adds (backoff step - first step) to a delayed NAK from the second retry on.
$run = runFailing($source . '&backoff[]=2&backoff[]=4&backoff[]=6&max_deliver=5', new MultiplierRetryStrategy(3, 1000, 1, 0, 0.0), 3, $always);
printf("   backoff 2 s, 4 s, 6 s with Symfony's delay 1 s: NATS redelivered after %s\n", seconds($run['gaps']));
assertGapsFollow('backoff', $run['gaps'], [1.0, 1.0, 1.0], [0.0, 2.0, 4.0]);
printf("OK backoff: a delayed NAK lands at Symfony's delay plus (backoff step - first step): +0, +2, +4 s\n");

// 6. max_deliver at or below max_retries + 1: Symfony retries, NATS does not redeliver, and the transport says so.
$run = runFailing($source . '&max_deliver=2', new MultiplierRetryStrategy(3, 500, 1, 0, 0.0), 99, $always, 3.0);
if ($run['attempts'] !== 2 || !$run['log']->logged('max_deliver is 2')) {
    throw new RuntimeException(sprintf('max_deliver: expected 2 attempts and a warning; got %d attempt(s)', $run['attempts']));
}
printf("OK max_deliver 2 below max_retries 3: the handler ran %d times, NATS did not redeliver, the transport logged a warning; %s pending acknowledgement\n", $run['attempts'], var_export($run['consumer']['num_ack_pending'] ?? null, true));

// 7. For comparison, retry_delay_source=nats (the default): every redelivery comes nak_delay after the failure.
$run = runFailing('retry_handler=nats&nak_delay=1', new MultiplierRetryStrategy(3, 3000, 2, 0, 0.0), 2, $always);
printf("   retry_delay_source=nats, nak_delay 1 s: Symfony computed %s, NATS redelivered after %s\n", seconds($run['delays']), seconds($run['gaps']));
