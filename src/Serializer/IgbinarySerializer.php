<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Serializer;

use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class IgbinarySerializer extends AbstractEnveloperSerializer implements SerializerInterface
{
    protected function serialize(Envelope $envelope): string
    {
        return \igbinary_serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        return \igbinary_unserialize($data);
    }
}