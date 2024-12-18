<?php

namespace IDCT\NatsMessenger;

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class NatsTransportFactory implements TransportFactoryInterface
{
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        //this is meant to be snappy so we ignore the provided serializer in favor of igbinary
        return new NatsTransport($dsn, $options);
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return 0 === strpos($dsn, 'nats-jetstream://');
    }
}