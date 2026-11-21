<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

/**
 * An object of the application's whose __wakeup() decodes a message it carries with the igbinary serializer, so that
 * decode runs while the application's own unserialize() runs. It keeps what the decode gave, or how it failed (#122).
 */
final class DecodesOnWakeup
{
    public Envelope|MessageDecodingFailedException|null $decoded = null;

    public function __construct(public readonly string $body)
    {
    }

    public function __wakeup(): void
    {
        try {
            $this->decoded = (new IgbinarySerializer())->decode(['body' => $this->body]);
        } catch (MessageDecodingFailedException $failure) {
            $this->decoded = $failure;
        }
    }
}
