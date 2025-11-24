<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class SimdDecodingJsonSerializer extends AbstractEnveloperSerializer implements SerializerInterface
{
    public function __construct()
    {
        if (!\function_exists('simdjson_decode')) {
            throw new \RuntimeException('The simdjson extension is not installed.');
        }
    }

    protected function serialize(Envelope $envelope): string
    {
        return \json_encode($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        return \simdjson_decode($data);
    }
}