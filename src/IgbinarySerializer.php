<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class IgbinarySerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        if (empty($encodedEnvelope['body'])) {
            throw new MessageDecodingFailedException('Encoded envelope should have at least a "body".');
        }

        if (!extension_loaded('igbinary')) {
            throw new \RuntimeException('The igbinary extension is required to decode messages.');
        }

        $envelope = \igbinary_unserialize($encodedEnvelope['body']);

        if (!$envelope instanceof Envelope) {
            throw new \RuntimeException('Invalid envelope');
        }

        return $envelope;
    }

    public function encode(Envelope $envelope): array
    {
        if (!extension_loaded('igbinary')) {
            throw new \RuntimeException('The igbinary extension is required to encode messages.');
        }

        return [
            'body' => \igbinary_serialize($envelope),
        ];
    }
}