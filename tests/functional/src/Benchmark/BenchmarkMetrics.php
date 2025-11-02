<?php

namespace App\Benchmark;

/**
 * Benchmark metrics collector and calculator.
 *
 * Tracks memory usage, execution time, and throughput metrics
 * for performance benchmarking.
 */
class BenchmarkMetrics
{
    private float $startTime = 0;
    private float $endTime = 0;
    private int $startMemory = 0;
    private int $peakMemory = 0;
    private int $endMemory = 0;
    private int $messageCount = 0;
    private int $batchSize = 0;

    public function __construct(int $messageCount, int $batchSize)
    {
        $this->messageCount = $messageCount;
        $this->batchSize = $batchSize;
    }

    public function start(): void
    {
        gc_collect_cycles();
        $this->startMemory = memory_get_usage(true);
        $this->startTime = microtime(true);
    }

    public function end(): void
    {
        $this->endTime = microtime(true);
        $this->endMemory = memory_get_usage(true);
        $this->peakMemory = memory_get_peak_usage(true);
    }

    public function getTotalTime(): float
    {
        return $this->endTime - $this->startTime;
    }

    public function getMemoryUsed(): int
    {
        return $this->endMemory - $this->startMemory;
    }

    public function getPeakMemory(): int
    {
        return $this->peakMemory;
    }

    public function getThroughput(): float
    {
        $totalTime = $this->getTotalTime();
        if ($totalTime === 0.0) {
            return 0.0;
        }
        return $this->messageCount / $totalTime;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Format memory bytes to human-readable format.
     */
    public static function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format time to human-readable format.
     */
    public static function formatTime(float $seconds): string
    {
        if ($seconds < 0.001) {
            return round($seconds * 1000000, 2) . ' μs';
        } elseif ($seconds < 1) {
            return round($seconds * 1000, 2) . ' ms';
        } else {
            return round($seconds, 2) . ' s';
        }
    }

    /**
     * Format throughput (messages per second).
     */
    public static function formatThroughput(float $mps): string
    {
        if ($mps >= 1000000) {
            return round($mps / 1000000, 2) . 'M msg/s';
        } elseif ($mps >= 1000) {
            return round($mps / 1000, 2) . 'K msg/s';
        } else {
            return round($mps, 2) . ' msg/s';
        }
    }
}
