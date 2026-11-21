<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * A message whose __wakeup() unserializes an optional cache of its own, with @, and finds it broken: the warning comes
 * from an unserialize() call too, one inside the decoder's, and is the application's to handle. The message decodes
 * (#122).
 */
final class OwnCacheOnWakeup
{
    public function __construct(public readonly string $text)
    {
    }

    public function __wakeup(): void
    {
        @unserialize('a broken cache entry');
    }
}
