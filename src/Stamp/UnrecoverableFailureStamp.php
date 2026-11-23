<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Whether every handler of a message that failed failed for good: every exception the HandlerFailedException wraps
 * directly is an UnrecoverableExceptionInterface, the rule Symfony's retry listener goes by.
 *
 * {@see \IDCT\NatsMessenger\EventListener\AddUnrecoverableFailureStampListener} adds it to the envelope of a failed
 * delivery a transport in nats mode received ({@see ReceivedInNatsModeStamp}), `true` or `false`, and the transport
 * TERMs the delivery when it is `true` and NAKs it when it is `false`. Without it the transport goes by Symfony's
 * ErrorDetailsStamp, which names only the first exception, so with several handlers the handler that happened to run
 * first decided (#125).
 *
 * It is not sendable: it describes one delivery, and a copy Symfony sends (a retry, a failure-transport copy) runs its
 * handlers again. The serializers strip it, and the listener adds it after Symfony's retry and failure-transport
 * listeners have taken their copies, so a transport that stores envelopes as they are does not keep it either.
 */
final class UnrecoverableFailureStamp implements NonSendableStampInterface
{
    /**
     * @param bool $unrecoverable Whether every exception the HandlerFailedException wraps directly is unrecoverable
     */
    public function __construct(public readonly bool $unrecoverable)
    {
    }
}
