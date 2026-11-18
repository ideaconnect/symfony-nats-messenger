<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

/**
 * How a retry that Symfony's retry strategy decides on reaches NATS, with {@see RetryHandler::SYMFONY}.
 *
 * - **COPY** (the default): Symfony's retry copy is published as a new message, and the delivery that failed is
 *   TERMed. The copy waits for its delay only with scheduled_messages (NATS 2.12 and later), at a whole-second time
 *   rounded up; without it the copy is delivered at once.
 * - **NAK**: the copy is not published. The delivery that failed is NAKed with the delay Symfony computed, to the
 *   millisecond, on any supported server, and NATS redelivers the original. The retry count Symfony reads comes from
 *   the delivery count. Each retry holds one of the consumer's max_ack_pending slots while it waits.
 *
 * A message Symfony gives up on is TERMed with either value, and a failure transport receives one copy of it. The
 * builder refuses the option with {@see RetryHandler::NATS}, where Symfony's retry strategy has no effect.
 *
 * @see NatsTransportConfiguration::retryVia() Returns the active value.
 */
enum RetryVia: string
{
    case COPY = 'copy';
    case NAK = 'nak';
}
