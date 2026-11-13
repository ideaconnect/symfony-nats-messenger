<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

/**
 * Where the delay before a redelivery comes from when NATS redelivers ({@see RetryHandler::NATS}).
 *
 * - **NATS**: the transport's own `nak_delay`, or the consumer's `backoff`. Symfony's retry strategy has no effect.
 * - **SYMFONY**: the delay Symfony's retry strategy computed for the failure (delay, multiplier, jitter, max_delay,
 *   and the delay a {@see \Symfony\Component\Messenger\Exception\RecoverableExceptionInterface} carries). The
 *   transport NAKs the delivery with that delay. A delivery Symfony does not retry is NAKed without one, so that
 *   max_deliver leaves the message in the stream ({@see NoRetryAction} for one max_deliver would still redeliver).
 *
 * @see NatsTransportConfiguration::retryDelaySource() Returns the active source.
 */
enum RetryDelaySource: string
{
    case NATS = 'nats';
    case SYMFONY = 'symfony';
}
