<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

/**
 * How a message Symfony's retry strategy gives up on is settled in NATS, with {@see RetryVia::NAK}.
 *
 * - **TERM** (the default): the delivery is TERMed. NATS publishes the MSG_TERMINATED advisory, a stream with
 *   limits retention keeps the message and one with work-queue or interest retention removes it. A failure
 *   transport receives one copy.
 * - **NAK**: "dead in place". The last delivery max_deliver allows is NAKed without a delay: NATS redelivers nothing
 *   after it, keeps the message in the stream whatever its retention, and publishes the MAX_DELIVERIES advisory.
 *   That is the delivery Symfony gives up on when max_deliver is the retry strategy's max_retries + 1. A message
 *   Symfony gives up on earlier (a failure it never retries, or a max_deliver above max_retries + 1) is TERMed on
 *   a stream with limits retention, which keeps it, and its handler runs once. On a stream configured with
 *   work-queue or interest retention, where a TERM would remove it, it is NAKed too, so its handler runs again
 *   until the last delivery. No failure transport receives a copy, when the transport knows its name
 *   ({@see NatsTransportConfiguration::transportName()}): the message stays where it is instead.
 *
 * The builder refuses NAK without {@see RetryVia::NAK} and without max_deliver.
 *
 * @see NatsTransportConfiguration::giveUpVia() Returns the active value.
 */
enum GiveUpVia: string
{
    case TERM = 'term';
    case NAK = 'nak';
}
