<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NatsMessenger\NatsTransportFactory;

/** An application service that asks for the transport factory by its class, as autowiring resolves it. */
final class TransportFactoryUser
{
    public function __construct(public readonly NatsTransportFactory $factory)
    {
    }
}
