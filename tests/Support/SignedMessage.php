<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * A message whose handler, {@see SignedMessageHandler}, the bundle tests register with `sign: true`, so that
 * Symfony's SigningSerializer signs it on encode() and refuses to decode it without a valid signature.
 */
final class SignedMessage
{
    public function __construct(public readonly string $text)
    {
    }
}
