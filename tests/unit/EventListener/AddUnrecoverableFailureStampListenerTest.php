<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\EventListener;

use IDCT\NatsMessenger\EventListener\AddUnrecoverableFailureStampListener;
use IDCT\NatsMessenger\Stamp\UnrecoverableFailureStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The listener that tells nats mode whether every handler of a failed message failed for good (#125), by Symfony's
 * rule: every exception the HandlerFailedException wraps directly is unrecoverable.
 */
final class AddUnrecoverableFailureStampListenerTest extends TestCase
{
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
        $envelope = new Envelope(new \stdClass());
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
        $envelope = new Envelope(new \stdClass());
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
}
