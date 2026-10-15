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
    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }
}
