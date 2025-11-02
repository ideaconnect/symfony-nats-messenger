<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for benchmark messages.
 *
 * This handler performs minimal operations - just counts the message.
 * No file I/O, database writes, or other blocking operations.
 * Designed to measure pure messaging system overhead.
 */
#[AsMessageHandler]
class BenchmarkMessageHandler
{
    private static int $processedCount = 0;

    public function __invoke(BenchmarkMessage $message): void
    {
        self::$processedCount++;
    }

    public static function getProcessedCount(): int
    {
        return self::$processedCount;
    }

    public static function reset(): void
    {
        self::$processedCount = 0;
    }
}
