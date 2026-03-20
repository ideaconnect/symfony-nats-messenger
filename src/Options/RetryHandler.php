<?php

namespace IDCT\NatsMessenger\Options;

/**
 * Retry handling strategy for failed message deliveries.
 *
 * Controls how the transport responds to rejected envelopes:
 * - **SYMFONY**: Sends TERM to JetStream (stop redelivery); Symfony's retry/failure transport handles retries.
 * - **NATS**: Sends NAK to JetStream; NATS handles redelivery natively using server-side retry policies.
 *
 * @see NatsTransportConfiguration::retryHandler() Returns the active strategy.
 * @see NatsTransport::handleFailedDelivery()      Applies the strategy.
 */
enum RetryHandler: string
{
    case SYMFONY = 'symfony';
    case NATS = 'nats';
}
