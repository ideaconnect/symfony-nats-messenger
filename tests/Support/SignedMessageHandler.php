<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/** The handler that makes {@see SignedMessage} a message that must be signed. The tests never call it. */
final class SignedMessageHandler
{
    public function __invoke(SignedMessage $message): void
    {
    }
}
