<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * A message that restores itself in __unserialize() and raises a notice there. The notice is the application's to
 * handle, and the message decodes (#122).
 */
final class NoticeOnUnserialize
{
    public function __construct(public readonly string $text)
    {
    }

    /** @return array{text: string} */
    public function __serialize(): array
    {
        return ['text' => $this->text];
    }

    /** @param array{text: string} $data */
    public function __unserialize(array $data): void
    {
        trigger_error('restored from an older format', E_USER_NOTICE);
        $this->text = $data['text'];
    }
}
