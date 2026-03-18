<?php

namespace IDCT\NatsMessenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Base serializer for messenger envelopes.
 *
 * Provides the common encode/decode structure expected by Symfony Messenger's transport layer.
 * Concrete serializers only need to implement {@see serialize()} and {@see deserialize()}
 * for the raw payload format (e.g. igbinary, JSON, msgpack).
 *
 * The decode path validates that the encoded envelope contains a non-empty 'body' key
 * and that deserialization produces a valid Envelope instance.
 *
 * @see IgbinarySerializer Concrete implementation using igbinary.
 */
abstract class AbstractEnveloperSerializer implements SerializerInterface
{
    /**
     * Decodes a transport payload into an envelope.
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        if (!array_key_exists('body', $encodedEnvelope)) {
            throw new MessageDecodingFailedException('Encoded envelope should at least have a "body".');
        }

        $body = $encodedEnvelope['body'];
        if (!is_string($body) || $body === '') {
            throw new MessageDecodingFailedException('Encoded envelope should at least have a "body".');
        }

        $envelope = $this->deserialize($body);

        if (!$envelope instanceof Envelope) {
            throw new \RuntimeException('Invalid envelope');
        }

        return $envelope;
    }

    /**
     * Encodes an envelope to transport payload shape.
     *
     * @return array{body: string}
     */
    public function encode(Envelope $envelope): array
    {
        return [
            'body' => $this->serialize($envelope),
        ];
    }

    /**
     * Serializes an envelope into raw binary/text payload.
     */
    abstract protected function serialize(Envelope $envelope): string;

    /**
     * Deserializes raw payload back to envelope-compatible data.
     */
    abstract protected function deserialize(string $data): mixed;
}