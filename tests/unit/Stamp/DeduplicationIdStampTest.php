<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Stamp;

use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeduplicationIdStampTest extends TestCase
{
    public function testKeepsTheId(): void
    {
        self::assertSame('order-42', (new DeduplicationIdStamp('order-42'))->id);
    }

    /**
     * The id is published in a header, which cannot carry a line break, and an empty one identifies nothing.
     */
    #[DataProvider('idsAHeaderCannotCarry')]
    public function testRejectsAnIdAHeaderCannotCarry(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeduplicationIdStamp($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsAHeaderCannotCarry(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'carriage return' => ["order\r42"];
        yield 'line feed' => ["order\n42"];
    }
}
