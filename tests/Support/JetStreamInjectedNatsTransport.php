<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;

/**
 * The transport with the JetStream context the test gives it, and no connection of its own, so that a test can
 * script the JetStream calls with a mock.
 */
final class JetStreamInjectedNatsTransport extends NatsTransport
{
    public function setJetStreamContext(JetStreamContext $jetStream): void
    {
        $this->jetStream = $jetStream;
    }

    protected function connect(): void
    {
        // Keep unit tests isolated from live NATS.
    }
}
