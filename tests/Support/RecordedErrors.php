<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * Runs code under an error handler that records each error it gets and lets it go, as Symfony's does outside debug
 * mode, and restores the handler there was afterwards (#122).
 */
final class RecordedErrors
{
    /**
     * @template T
     *
     * @param callable(): T $run
     *
     * @return array{T, list<array{int, string}>} what the code gave, and the type and message of each error the
     *     handler got
     */
    public static function during(callable $run): array
    {
        $seen = [];
        set_error_handler(static function (int $type, string $message) use (&$seen): bool {
            $seen[] = [$type, $message];

            return true;
        });

        try {
            $result = $run();
        } finally {
            restore_error_handler();
        }

        return [$result, $seen];
    }
}
