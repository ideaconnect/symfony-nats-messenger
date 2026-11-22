<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Marks a delivery a transport in nats mode (`retry_handler: nats`) received, with that delivery's token.
 *
 * {@see \IDCT\NatsMessenger\NatsTransport::get()} adds it in nats mode, next to the TransportMessageIdStamp holding the
 * same token. {@see \IDCT\NatsMessenger\EventListener\AddUnrecoverableFailureStampListener} classifies a failure only
 * when the envelope carries it and its token is the one in the envelope's last TransportMessageIdStamp, since only nats
 * mode reads the {@see UnrecoverableFailureStamp}: the envelopes of other receivers (an in-memory or Doctrine
 * transport, a NATS transport in symfony mode) are left as they are. So is a copy of a nats-mode delivery that another
 * transport stores without a serializer, such as an in-memory failure transport, when that transport gives the copy a
 * TransportMessageIdStamp of its own, with another id, as the in-memory one does. Symfony leaves that id to the
 * transport: a custom transport that stores the envelope as it is and returns it without an id of its own keeps this
 * marker and the NATS delivery's id on the copy, whose failure in another worker is then classified too. Such a
 * transport drops the non-sendable stamps when it takes an envelope, as Symfony's serializers do, or gives it an id of
 * its own (README, "Retry Handler Behavior").
 *
 * It is not sendable: it describes one delivery, and the serializers strip it.
 */
final class ReceivedInNatsModeStamp implements NonSendableStampInterface
{
    /**
     * @param string $deliveryToken The delivery's reply subject, which the delivery's TransportMessageIdStamp holds
     */
    public function __construct(public readonly string $deliveryToken)
    {
    }
}
