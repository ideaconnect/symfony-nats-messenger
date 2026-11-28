<?php

/**
 * NATS retry handler with several handlers - in `retry_handler: nats` a message whose handlers failed is TERMed only
 * when every one of them failed for good, the rule Symfony goes by (#125).
 *
 * Runs Symfony's own Worker, with its error-details and retry listeners as `messenger:consume` does, and with
 * `AddUnrecoverableFailureStampListener`, which the bundle registers and a worker built by hand adds itself, over a
 * message with two handlers. One fails for good (an UnrecoverableMessageHandlingException on every run), the other
 * once (a RuntimeException, then it succeeds). Symfony wraps both failures in one HandlerFailedException, and treats
 * it as unrecoverable only when every exception in it is: the first delivery is NAKed whichever handler runs first,
 * NATS redelivers the message, and the second delivery, where only the unrecoverable failure is left, is TERMed.
 * JetStream publishes MSG_NAKED and then MSG_TERMINATED for the message. In 5.10.0 and 5.10.1, when Symfony did not
 * request a retry, the first wrapped exception decided whether the transport sent TERM or NAK, read from Symfony's
 * ErrorDetailsStamp, which names that one only: with the unrecoverable handler first and `max_retries: 0` the first
 * delivery was TERMed, and the other handler never ran again. Earlier releases NAKed every handler failure.
 *
 * With `max_retries: 0`, as the README asks for in nats mode, Symfony retries nothing and the worker rejects the
 * failure; with `max_retries: 3` Symfony retries the first failure itself, the transport leaves its copy out and NAKs
 * the delivery instead, whatever the first exception is. Both settings, both orders. What Symfony decided is checked
 * too, as `WorkerMessageFailedEvent::willRetry()` after its retry listener: no retry with `max_retries: 0`
 * (`[false, false]`), and with `max_retries: 3` one for the mix and none once the unrecoverable failure is left alone
 * (`[true, false]`), so a run where Symfony's retry did not take place fails. Then two handlers that both fail for
 * good: the first delivery is TERMed, and Symfony retries nothing.
 *
 * Mirrors the README "Retry Handler Behavior" section. Needs the test server (composer nats:start).
 * Run: php examples/nats-retry-several-handlers.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\EventListener\AddUnrecoverableFailureStampListener;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * One run: a message with two handlers, failing as $failures says ('for good' or 'once', in the order they run),
 * through Symfony's Worker in nats mode with max_deliver 3 and the given max_retries, until $deliveries deliveries
 * came or 10 s passed. Returns how often each handler ran, whether Symfony asked for a retry of each failed delivery
 * (`willRetry()`, read after its retry listener decided), the NAK, TERM and MAX_DELIVERIES advisories of the consumer
 * as "KIND of <stream sequence>", in the order JetStream published them, and what the consumer holds.
 *
 * @param list<string> $failures
 * @return array{runs: list<int>, willRetry: list<bool>, advisories: list<string>, ackPending: mixed, pending: mixed}
 */
$run = static function (array $failures, int $maxRetries, int $deliveries): array {
    $stream = exampleStream('nats_retry_several_handlers');
    $transport = new NatsTransport(exampleDsn($stream, 'retry_handler=nats&max_deliver=3'), ['max_batch_timeout' => 0.2], new PhpSerializer());
    $listener = (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream))->client;
    try {
        $transport->setup();
        $listener->connect()->await();
        $advisories = $listener->subscribeQueue('$JS.EVENT.ADVISORY.CONSUMER.*.' . $stream . '.client')->await();
        $listener->flush()->await();
        $transport->send(new Envelope(new ExampleMessage('invoice 7')));

        $runs = array_fill(0, count($failures), 0);
        $handlers = [];
        foreach ($failures as $index => $failing) {
            // A name of its own for each handler: Symfony runs one handler per name.
            $handlers[] = new HandlerDescriptor(static function () use ($index, $failing, &$runs): void {
                $runs[$index]++;
                if ($failing === 'for good') {
                    throw new UnrecoverableMessageHandlingException('the invoice does not exist');
                }
                if ($runs[$index] === 1) {
                    throw new RuntimeException('the payment provider did not answer');
                }
            }, ['alias' => 'handler-' . $index]);
        }
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([ExampleMessage::class => $handlers]))]);

        // What messenger:consume wires up, and the listener the bundle registers, which a worker built by hand adds.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(locatorOf($transport), locatorOf(new MultiplierRetryStrategy($maxRetries))));
        $dispatcher->addSubscriber(new AddUnrecoverableFailureStampListener());
        // What Symfony decided for each failed delivery: at priority 0, after its retry listener (100).
        $willRetry = [];
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$willRetry): void {
            $willRetry[] = $event->willRetry();
        });
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($deliveries));
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(10));
        (new Worker(['nats' => $transport], $bus, $dispatcher))->run(['sleep' => 100000]);

        $published = [];
        $advisories->setTimeout(0.5);
        while (($advisory = $advisories->next()) !== null) {
            $kind = explode('.', $advisory->subject)[4] ?? '?';
            if (in_array($kind, ['MSG_NAKED', 'MSG_TERMINATED', 'MAX_DELIVERIES'], true)) {
                $payload = json_decode($advisory->payload, true);
                $published[] = sprintf('%s of %s', $kind, json_encode(is_array($payload) ? ($payload['stream_seq'] ?? null) : null));
            }
        }
        $consumer = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);

        return ['runs' => $runs, 'willRetry' => $willRetry, 'advisories' => $published, 'ackPending' => $consumer['num_ack_pending'] ?? null, 'pending' => $consumer['num_pending'] ?? null];
    } finally {
        $listener->disconnect()->await();
        $transport->close();
        deleteExampleStream($stream);
    }
};

$checked = [];
foreach ([0, 3] as $maxRetries) {
    foreach ([['for good', 'once'], ['once', 'for good']] as $failures) {
        $what = sprintf('max_retries %d, the first handler failing %s and the second %s', $maxRetries, $failures[0], $failures[1]);
        $result = $run($failures, $maxRetries, 2);
        // Symfony retries the mix only when max_retries allows it, and never the unrecoverable failure left alone.
        $expected = ['runs' => [2, 2], 'willRetry' => [$maxRetries > 0, false], 'advisories' => ['MSG_NAKED of 1', 'MSG_TERMINATED of 1'], 'ackPending' => 0, 'pending' => 0];
        if ($result !== $expected) {
            throw new RuntimeException(sprintf('%s: expected %s, got %s', $what, json_encode($expected), json_encode($result)));
        }
        $checked[] = $what;
    }
}

$result = $run(['for good', 'for good'], 0, 1);
$expected = ['runs' => [1, 1], 'willRetry' => [false], 'advisories' => ['MSG_TERMINATED of 1'], 'ackPending' => 0, 'pending' => 0];
if ($result !== $expected) {
    throw new RuntimeException(sprintf('two handlers failing for good: expected %s, got %s', json_encode($expected), json_encode($result)));
}

printf(
    "OK nats-retry-several-handlers: a mix of an unrecoverable failure and an ordinary one was NAKed and then, with the unrecoverable one left alone, TERMed (MSG_NAKED, then MSG_TERMINATED, each handler run twice) in all %d runs (%s), Symfony asking for a retry of the mix with max_retries 3 only; two handlers failing for good were TERMed at the first delivery\n",
    count($checked),
    implode('; ', $checked),
);
