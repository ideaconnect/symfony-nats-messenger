<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * A serializer that reads each header name as the string decode()'s contract declares, under strict types, as an
 * application's own serializer may: it picks out its headers by prefix, so a name that is an int key makes
 * str_starts_with() throw a TypeError. It records what each decode() call was given, before reading the names.
 */
final class StrictHeaderNameSerializer implements SerializerInterface
{
    /** @var list<array<mixed>> */
    public array $decoded = [];

    public function decode(array $encodedEnvelope): Envelope
    {
        $this->decoded[] = $encodedEnvelope;

        $applicationHeaders = [];
        foreach ($encodedEnvelope['headers'] ?? [] as $name => $value) {
            if (str_starts_with($name, 'X-App-')) {
                $applicationHeaders[$name] = $value;
            }
        }

        return new Envelope((object) $applicationHeaders);
    }

    public function encode(Envelope $envelope): array
    {
        throw new LogicException('Only decode() is used.');
    }
}
