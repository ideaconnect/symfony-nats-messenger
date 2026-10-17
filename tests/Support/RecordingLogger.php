<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps every record, for tests that check what the NATS client reports to the logger it is given.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * The messages logged at the given level, in order.
     *
     * @return list<string>
     */
    public function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter($this->records, static fn (array $record): bool => $record['level'] === $level),
        ));
    }
}
