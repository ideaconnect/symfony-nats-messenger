<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Base serializer for messenger envelopes.
 *
 * Provides the common encode/decode structure expected by Symfony Messenger's transport layer.
 * Concrete serializers only need to implement {@see serialize()} and {@see deserialize()}
 * for the raw payload format (e.g. igbinary, JSON, msgpack).
 *
 * The decode path validates that the encoded envelope contains a non-empty 'body' key
 * and that deserialization produces a valid Envelope instance. Every way it fails is a
 * MessageDecodingFailedException, as with Symfony's own serializers, which is what a
 * transport settles as a message that cannot be decoded.
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
        // A single gate for a usable body: '?? null' folds the missing-key case into the same check
        // that rejects a null, non-string, or empty body - all of which are equally undecodable and
        // share one error message. A separate array_key_exists() guard would be redundant.
        $body = $encodedEnvelope['body'] ?? null;
        if (!is_string($body) || $body === '') {
            throw new MessageDecodingFailedException('Encoded envelope should at least have a "body".');
        }

        $envelope = $this->deserializeOrFail($body);

        if (!$envelope instanceof Envelope) {
            throw new MessageDecodingFailedException('Deserialized data is not a valid Symfony Messenger Envelope.');
        }

        return $envelope;
    }

    /**
     * {@see deserialize()}, with every way it fails made a MessageDecodingFailedException, as Symfony's PhpSerializer
     * does: an exception it throws, and a warning or notice it raises, which Symfony's error handler throws as an
     * ErrorException in debug mode (igbinary warns about a payload that is not igbinary). A transport takes only that
     * exception for a message that cannot be decoded, as Symfony's own transports do, and lets any other through,
     * with the message left for redelivery. A deprecation goes to the error handler there was, as without this.
     */
    private function deserializeOrFail(string $body): mixed
    {
        $previous = null;
        $previous = set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if ($type === E_DEPRECATED || $type === E_USER_DEPRECATED) {
                return $previous !== null && $previous($type, $message, $file, $line) !== false;
            }

            throw new MessageDecodingFailedException('Could not decode the envelope: ' . $message);
        });

        try {
            return $this->deserialize($body);
        } catch (MessageDecodingFailedException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new MessageDecodingFailedException('Could not decode the envelope: ' . $failure->getMessage(), 0, $failure);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Encodes an envelope to transport payload shape.
     *
     * Stamps marked {@see NonSendableStampInterface} are removed first, as Symfony's own serializers do: they
     * describe the envelope's journey through this process and are not meant to travel. One of them, the
     * AckStamp a worker adds to every message it handles, holds a closure, which cannot be serialized. Every
     * re-send of a received message - Symfony's retry, or its failure transport - failed on it and stopped
     * the worker before it could reject the message, which JetStream then redelivered into the same failure,
     * over and over (#46).
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $envelope = $envelope->withoutStampsOfType(NonSendableStampInterface::class);

        return [
            'body' => $this->serialize($envelope),
            'headers' => $this->headers($envelope),
        ];
    }

    /**
     * Returns headers to include with the transport message.
     *
     * Override in concrete serializers to propagate envelope metadata as NATS headers.
     * Default implementation returns an empty array (no transport-level headers).
     *
     * @return array<string, string>
     */
    protected function headers(Envelope $envelope): array
    {
        return [];
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