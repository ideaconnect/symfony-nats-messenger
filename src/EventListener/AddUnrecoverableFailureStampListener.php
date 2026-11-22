<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\EventListener;

use IDCT\NatsMessenger\Stamp\ReceivedInNatsModeStamp;
use IDCT\NatsMessenger\Stamp\UnrecoverableFailureStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Tells the transport in nats mode whether a message whose handlers failed can never succeed, by Symfony's own rule:
 * only when every exception the HandlerFailedException wraps directly is an UnrecoverableExceptionInterface, as
 * Symfony's retry listener decides. A mix of an unrecoverable failure and an ordinary one can still succeed on a
 * redelivery, in either order of the handlers, and is NAKed (#125).
 *
 * The transport's reject() sees only the envelope, and Symfony's ErrorDetailsStamp on it names the first exception a
 * HandlerFailedException wraps, so without this listener the handler that ran first decided for all of them: an
 * unrecoverable failure first TERMed the message, and the other handler's failure was never tried again. The worker
 * passes the envelope of this event to reject(), with the {@see UnrecoverableFailureStamp} this listener adds to it:
 * `true` or `false` for a HandlerFailedException, nothing for any other throwable, which the ErrorDetailsStamp names
 * as it is. The transport reads `false` too, before the ErrorDetailsStamp.
 *
 * Only a delivery a transport in nats mode received is classified: one that carries a {@see ReceivedInNatsModeStamp}
 * whose token is the one in its last TransportMessageIdStamp. The envelopes of other receivers, which nothing reads the
 * stamp of, are left as they are, and so is a copy of a nats-mode delivery stored in another transport that gives the
 * copy an id of its own, as the in-memory transport does, or serializes it, which drops the marker. Symfony leaves that
 * id to the transport, so a custom transport that stores the envelope as it is and returns it without an id of its own
 * keeps the marker and the NATS delivery's id on the copy, and a failure of that copy in another worker is classified
 * too. Nothing but nats mode reads the stamp, and the NATS delivery was settled already; such a transport keeps its
 * envelopes as they were sent by dropping the non-sendable stamps when it takes one, as Symfony's serializers do, or
 * by giving each one a TransportMessageIdStamp of its own (README, "Retry Handler Behavior").
 *
 * Priority -101: after Symfony's retry listener (100) and its failure-transport listener (-100) have taken their
 * copies of the envelope. The stamp is not sendable, so a serializer strips it, but a transport without one, such as
 * the in-memory transport, would keep it on a copy taken after it was added.
 *
 * The bundle registers it (`kernel.event_subscriber`). An application without the bundle registers it in its
 * services.yaml with that tag, and a worker built by hand adds it to its event dispatcher (`addSubscriber()`); the
 * README's "Retry Handler Behavior" has both.
 */
final class AddUnrecoverableFailureStampListener implements EventSubscriberInterface
{
    /** After Symfony's SendFailedMessageForRetryListener (100) and SendFailedMessageToFailureTransportListener (-100). */
    public const PRIORITY = -101;

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $failure = $event->getThrowable();
        if (!$failure instanceof HandlerFailedException || !self::wasReceivedInNatsMode($event->getEnvelope())) {
            return;
        }

        $event->addStamps(new UnrecoverableFailureStamp(self::everyExceptionIsUnrecoverable($failure)));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', self::PRIORITY]];
    }

    /**
     * Whether a transport in nats mode received this delivery: its marker names the delivery the envelope's last
     * TransportMessageIdStamp holds, which a copy in another transport loses when that transport gives it an id of its
     * own.
     */
    private static function wasReceivedInNatsMode(Envelope $envelope): bool
    {
        $marker = $envelope->last(ReceivedInNatsModeStamp::class);

        return $marker !== null && $marker->deliveryToken === $envelope->last(TransportMessageIdStamp::class)?->getId();
    }

    /**
     * Whether every exception the failure wraps directly is unrecoverable. A HandlerFailedException wrapped in it
     * counts as an ordinary failure, as for Symfony's retry listener, which looks at the direct ones only.
     */
    private static function everyExceptionIsUnrecoverable(HandlerFailedException $failure): bool
    {
        foreach ($failure->getWrappedExceptions() as $exception) {
            if (!$exception instanceof UnrecoverableExceptionInterface) {
                return false;
            }
        }

        return true;
    }
}
