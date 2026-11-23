<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\EventListener;

use IDCT\NatsMessenger\EventListener\AddUnrecoverableFailureStampListener;
use IDCT\NatsMessenger\Stamp\ReceivedInNatsModeStamp;
use IDCT\NatsMessenger\Stamp\UnrecoverableFailureStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * The listener that tells nats mode whether every handler of a failed message failed for good (#125), by Symfony's
 * rule: every exception the HandlerFailedException wraps directly is unrecoverable. It classifies only a delivery a
 * transport in nats mode received.
 */
final class AddUnrecoverableFailureStampListenerTest extends TestCase
{
    /** A delivery token, as a JetStream reply subject. */
    private const DELIVERY = '$JS.ACK.test-stream.client.1.1.1.0.0';

    /**
     * One stamp, true only when every exception the HandlerFailedException wraps directly is unrecoverable, whatever
     * their order. False is a stamp too: without it the transport would go by the first exception, which TERMed the
     * mix whose first exception is unrecoverable.
     *
     * @param array<string, \Throwable> $exceptions
     */
    #[DataProvider('handlerFailures')]
    public function testStampsWhetherEveryHandlerFailedForGood(array $exceptions, bool $unrecoverable): void
    {
        $envelope = self::receivedInNatsMode();
        $event = new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, $exceptions));

        (new AddUnrecoverableFailureStampListener())->onMessageFailed($event);

        $stamps = $event->getEnvelope()->all(UnrecoverableFailureStamp::class);
        self::assertCount(1, $stamps);
        self::assertInstanceOf(UnrecoverableFailureStamp::class, $stamps[0]);
        self::assertSame($unrecoverable, $stamps[0]->unrecoverable);
    }

    /**
     * @return iterable<string, array{array<string, \Throwable>, bool}>
     */
    public static function handlerFailures(): iterable
    {
        $unrecoverable = static fn (): UnrecoverableMessageHandlingException => new UnrecoverableMessageHandlingException('the invoice does not exist');
        $ordinary = static fn (): \RuntimeException => new \RuntimeException('the payment provider did not answer');

        yield 'one handler, unrecoverable' => [['invoice' => $unrecoverable()], true];
        yield 'two handlers, both unrecoverable' => [['invoice' => $unrecoverable(), 'payment' => $unrecoverable()], true];
        yield 'unrecoverable first, ordinary second' => [['invoice' => $unrecoverable(), 'payment' => $ordinary()], false];
        yield 'ordinary first, unrecoverable second' => [['payment' => $ordinary(), 'invoice' => $unrecoverable()], false];
        yield 'two unrecoverable, then an ordinary one' => [['invoice' => $unrecoverable(), 'order' => $unrecoverable(), 'payment' => $ordinary()], false];
        yield 'one handler, ordinary' => [['payment' => $ordinary()], false];
        yield 'one handler, recoverable' => [['payment' => new RecoverableMessageHandlingException('try later')], false];
        // As for Symfony's retry listener, which looks at the exceptions wrapped directly: the inner handler failure is
        // not an UnrecoverableExceptionInterface itself.
        yield 'an unrecoverable failure inside a handler failure of its own' => [
            ['bus' => new HandlerFailedException(new Envelope(new \stdClass()), ['invoice' => $unrecoverable()])],
            false,
        ];
    }

    /**
     * A failure that is not a HandlerFailedException gets no stamp: the ErrorDetailsStamp names it as it is, and the
     * transport goes by that.
     */
    #[DataProvider('failuresThatAreNotAHandlerFailure')]
    public function testAddsNothingToAFailureThatIsNotAHandlerFailure(\Throwable $failure): void
    {
        $envelope = self::receivedInNatsMode();
        $event = new WorkerMessageFailedEvent($envelope, 'async', $failure);

        (new AddUnrecoverableFailureStampListener())->onMessageFailed($event);

        self::assertSame($envelope, $event->getEnvelope());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function failuresThatAreNotAHandlerFailure(): iterable
    {
        yield 'unrecoverable' => [new UnrecoverableMessageHandlingException('the invoice does not exist')];
        yield 'ordinary' => [new \RuntimeException('the connection was lost')];
    }

    /**
     * Only nats mode reads the stamp, so only a delivery a transport in nats mode received gets one: its
     * ReceivedInNatsModeStamp names the delivery its last TransportMessageIdStamp holds. Without that marker, or with
     * the marker of another delivery, as on a copy another transport stored with an id of its own, the envelope stays
     * as it is.
     */
    #[DataProvider('deliveriesNoTransportInNatsModeReceived')]
    public function testAddsNothingToADeliveryNoTransportInNatsModeReceived(Envelope $envelope): void
    {
        $failure = new HandlerFailedException($envelope, ['invoice' => new UnrecoverableMessageHandlingException('the invoice does not exist')]);
        $event = new WorkerMessageFailedEvent($envelope, 'async', $failure);

        (new AddUnrecoverableFailureStampListener())->onMessageFailed($event);

        self::assertSame($envelope, $event->getEnvelope());
    }

    /**
     * @return iterable<string, array{Envelope}>
     */
    public static function deliveriesNoTransportInNatsModeReceived(): iterable
    {
        yield 'no marker' => [new Envelope(new \stdClass(), [new TransportMessageIdStamp(self::DELIVERY)])];
        yield 'no marker and no delivery token' => [new Envelope(new \stdClass())];
        yield 'a marker without a delivery token' => [new Envelope(new \stdClass(), [new ReceivedInNatsModeStamp(self::DELIVERY)])];
        yield 'the marker of another delivery' => [new Envelope(new \stdClass(), [
            new TransportMessageIdStamp('$JS.ACK.test-stream.client.2.2.2.0.0'),
            new ReceivedInNatsModeStamp(self::DELIVERY),
        ])];
        yield 'a copy another transport stored with an id of its own' => [self::receivedInNatsMode()->with(new TransportMessageIdStamp(1))];
    }

    /**
     * In a worker whose receiver is Symfony's in-memory transport, a handler that fails for good leaves the envelope
     * the receiver rejects as it is: the subscriber, registered for every worker, skips a receiver that is not a NATS
     * transport in nats mode.
     */
    public function testLeavesTheFailureOfAnInMemoryReceiverAlone(): void
    {
        $receiver = new InMemoryTransport();
        $receiver->send(new Envelope(new \stdClass()));
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new UnrecoverableMessageHandlingException('the invoice does not exist');
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new AddUnrecoverableFailureStampListener());
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['memory' => $receiver], $bus, $dispatcher))->run(['sleep' => 0]);

        self::assertCount(1, $receiver->getRejected());
        self::assertNull($receiver->getRejected()[0]->last(UnrecoverableFailureStamp::class));
    }

    /**
     * After Symfony's error-details listener and its retry and failure-transport listeners, which take their copies
     * of the envelope before the stamp is on it: an in-memory transport without a serializer keeps the stamps of the
     * envelope it is given (the copies themselves are tested in NatsTransportTest, through the worker).
     */
    public function testRunsAfterSymfonysListenersHaveTakenTheirCopies(): void
    {
        self::assertSame(
            [WorkerMessageFailedEvent::class => ['onMessageFailed', -101]],
            AddUnrecoverableFailureStampListener::getSubscribedEvents(),
        );

        foreach ([AddErrorDetailsStampListener::class, SendFailedMessageForRetryListener::class, SendFailedMessageToFailureTransportListener::class] as $symfonys) {
            $subscribed = $symfonys::getSubscribedEvents()[WorkerMessageFailedEvent::class];
            self::assertIsArray($subscribed);
            self::assertGreaterThan(AddUnrecoverableFailureStampListener::PRIORITY, $subscribed[1], $symfonys);
        }
    }

    /**
     * An envelope as a transport in nats mode yields it: its delivery token, and the marker with the same token.
     */
    private static function receivedInNatsMode(): Envelope
    {
        return new Envelope(new \stdClass(), [new TransportMessageIdStamp(self::DELIVERY), new ReceivedInNatsModeStamp(self::DELIVERY)]);
    }
}
