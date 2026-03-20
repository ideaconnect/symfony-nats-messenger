<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Serializer;

use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * igbinary-backed envelope serializer.
 *
 * Uses the igbinary PHP extension for compact binary serialization of Symfony Messenger
 * envelopes. Requires ext-igbinary to be installed.
 *
 * ⚠ Security: igbinary_unserialize() instantiates arbitrary PHP objects from the payload.
 * Only use this serializer with trusted NATS subjects. See README security warnings.
 *
 * @see AbstractEnveloperSerializer Base class handling encode/decode envelope structure.
 */
final class IgbinarySerializer extends AbstractEnveloperSerializer implements SerializerInterface
{
    /**
     * Serializes an envelope with igbinary.
     */
    protected function serialize(Envelope $envelope): string
    {
        $serialized = \igbinary_serialize($envelope);

        if ($serialized === null) {
            throw new \RuntimeException('Failed to serialize envelope with igbinary.');
        }

        return $serialized;
    }

    /**
     * Deserializes igbinary payload back to envelope data.
     */
    protected function deserialize(string $data): mixed
    {
        return \igbinary_unserialize($data);
    }
}