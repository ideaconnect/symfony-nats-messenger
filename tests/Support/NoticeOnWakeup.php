<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * A message whose __wakeup() raises a notice of its own. The notice is the application's to handle, and the message
 * decodes (#122).
 */
final class NoticeOnWakeup
{
    public function __construct(public readonly string $text)
    {
    }

    public function __wakeup(): void
    {
        trigger_error('rebuilt a lazy field', E_USER_NOTICE);
    }
}
