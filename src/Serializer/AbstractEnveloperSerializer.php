<?php

namespace IDCT\NatsMessenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

abstract class AbstractEnveloperSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        if (empty($encodedEnvelope['body'])) {
            throw new MessageDecodingFailedException('Encoded envelope should at least have a "body".');
        }

        $envelope = \igbinary_unserialize($encodedEnvelope['body']);

        if (!$envelope instanceof Envelope) {
            throw new \RuntimeException('Invalid envelope');
        }

        return $envelope;
    }

    public function encode(Envelope $envelope): array
    {
        return [
            'body' => \igbinary_serialize($envelope),
        ];
    }

    abstract protected function serialize(Envelope $envelope): string;

    abstract protected function deserialize(string $data): mixed;
}