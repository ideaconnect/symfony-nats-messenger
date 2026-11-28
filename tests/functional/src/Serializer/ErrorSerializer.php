<?php

namespace App\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * A serializer of the application's own, as issue #126 describes: its decode() throws something other than a
 * MessageDecodingFailedException, as a schema registry or another service the real decoder depends on being
 * away would. encode() delegates to Symfony's PhpSerializer, so a message this serializer sends is a plain
 * Messenger envelope; decode() never reads the payload, so every message on the subject meets the same fault.
 */
final class ErrorSerializer implements SerializerInterface
{
    public function encode(Envelope $envelope): array
    {
        return (new PhpSerializer())->encode($envelope);
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        throw new \RuntimeException('schema registry: connection refused');
    }
}
