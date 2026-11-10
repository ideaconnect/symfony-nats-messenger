<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

/**
 * What happens, with {@see RetryDelaySource::SYMFONY}, to a delivery Symfony does not retry although max_deliver
 * allows more: an unrecoverable failure, or a max_retries below max_deliver - 1.
 *
 * - **NAK**: a plain NAK. NATS redelivers the message at once, and again after every such failure, until
 *   max_deliver; it then stays in the stream and the MAX_DELIVERIES advisory is published. The handler runs
 *   max_deliver times in all.
 * - **TERM**: NATS stops redelivering at once and publishes the MSG_TERMINATED advisory. The message stays in a
 *   stream with limits retention and is removed from one with work-queue or interest retention.
 *
 * The last delivery max_deliver allows is NAKed without a delay whichever is set.
 *
 * @see NatsTransportConfiguration::noRetryAction() Returns the active action.
 */
enum NoRetryAction: string
{
    case NAK = 'nak';
    case TERM = 'term';
}
