<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * A message whose __wakeup() raises a warning it suppresses, as legacy code does when it reads an optional field that
 * an older producer cut short: PHP calls the error handler for it all the same. The message decodes (#122).
 *
 * Not a cache file that is not there, as in the issue: the unit tests run with DG\BypassFinals, whose stream wrapper
 * adds a warning of its own to a file that cannot be opened.
 */
final class SuppressedWarningOnWakeup
{
    public function __construct(public readonly string $text)
    {
    }

    public function __wakeup(): void
    {
        @hex2bin('abc');
    }
}
