<?php

namespace App\Async;

/**
 * A message whose __wakeup() raises a warning it suppresses, as legacy code does when it looks for an optional cache
 * file: PHP calls the error handler for it all the same. 5.10.0 made such a message one that cannot be decoded, and
 * it was TERMed without its handler running (#122).
 */
class WarningOnWakeupMessage
{
    public function __construct(public int $messageId = 0)
    {
    }

    public function __wakeup(): void
    {
        // A cache file that is not there.
        @file_get_contents('/nonexistent/invoice-cache');
    }
}
