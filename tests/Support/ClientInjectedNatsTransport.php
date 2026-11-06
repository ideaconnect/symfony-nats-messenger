<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NATS\Core\NatsClient;
use IDCT\NatsMessenger\NatsTransport;

/**
 * The transport with the client the test gives it, so that a test can run the real client over a scripted server.
 */
final class ClientInjectedNatsTransport extends NatsTransport
{
    /** Seconds added to the transport's clock, so that a test can take a connection past ping_after_idle without waiting. */
    public float $clockOffset = 0.0;

    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }

    protected function monotonicSeconds(): float
    {
        return parent::monotonicSeconds() + $this->clockOffset;
    }
}
