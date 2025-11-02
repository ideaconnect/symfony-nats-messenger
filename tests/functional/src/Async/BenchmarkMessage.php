<?php

namespace App\Async;

/**
 * Lightweight message for benchmarking.
 *
 * This message type is designed for performance testing and does not perform
 * any I/O operations (file saving, database writes, etc.). It's optimized to
 * measure the overhead of the messaging system itself.
 */
class BenchmarkMessage
{
    public int $messageId = 0;
    public int $timestamp = 0;

    public function __construct(int $messageId = 0)
    {
        $this->messageId = $messageId;
        $this->timestamp = time();
    }
}
