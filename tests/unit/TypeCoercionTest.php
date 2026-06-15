<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\TypeCoercion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeCoercionTest extends TestCase
{
    #[DataProvider('intValueProvider')]
    public function testIntValue(mixed $value, int $default, int $expected): void
    {
        self::assertSame($expected, TypeCoercion::intValue($value, $default));
    }

    /**
     * @return iterable<string, array{mixed, int, int}>
     */
    public static function intValueProvider(): iterable
    {
        yield 'int passes through' => [5, 0, 5];
        yield 'negative int passes through' => [-7, 0, -7];
        yield 'zero int' => [0, 9, 0];
        yield 'float is truncated, not rounded' => [5.9, 0, 5];
        yield 'negative float truncates toward zero' => [-5.9, 0, -5];
        yield 'numeric string' => ['42', 0, 42];
        yield 'numeric decimal string truncates' => ['5.9', 0, 5];
        yield 'scientific notation string' => ['1e3', 0, 1000];
        yield 'non-numeric string returns default' => ['abc', 7, 7];
        yield 'empty string returns default' => ['', 3, 3];
        yield 'null returns default' => [null, 4, 4];
        yield 'bool true returns default (not handled)' => [true, 11, 11];
        yield 'bool false returns default (not handled)' => [false, 12, 12];
        yield 'array returns default' => [[1, 2], 8, 8];
        yield 'object returns default' => [new \stdClass(), 6, 6];
        yield 'default defaults to zero' => ['nope', 0, 0];
    }

    public function testIntValueDefaultIsZeroWhenOmitted(): void
    {
        self::assertSame(0, TypeCoercion::intValue('not-a-number'));
    }

    #[DataProvider('floatValueProvider')]
    public function testFloatValue(mixed $value, float $default, float $expected): void
    {
        self::assertSame($expected, TypeCoercion::floatValue($value, $default));
    }

    /**
     * @return iterable<string, array{mixed, float, float}>
     */
    public static function floatValueProvider(): iterable
    {
        yield 'float passes through' => [5.5, 0.0, 5.5];
        yield 'int widens to float' => [5, 0.0, 5.0];
        yield 'numeric string' => ['1.5', 0.0, 1.5];
        yield 'numeric integer string' => ['3', 0.0, 3.0];
        yield 'scientific notation string' => ['1e3', 0.0, 1000.0];
        yield 'non-numeric string returns default' => ['abc', 1.5, 1.5];
        yield 'null returns default' => [null, 2.5, 2.5];
        yield 'bool returns default (not handled)' => [true, 9.0, 9.0];
        yield 'array returns default' => [['x'], 4.0, 4.0];
        yield 'object returns default' => [new \stdClass(), 7.5, 7.5];
    }

    public function testFloatValueDefaultIsZeroWhenOmitted(): void
    {
        self::assertSame(0.0, TypeCoercion::floatValue([]));
    }

    #[DataProvider('stringValueProvider')]
    public function testStringValue(mixed $value, string $default, string $expected): void
    {
        self::assertSame($expected, TypeCoercion::stringValue($value, $default));
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function stringValueProvider(): iterable
    {
        yield 'string passes through' => ['hello', 'd', 'hello'];
        yield 'empty string passes through (not default)' => ['', 'd', ''];
        yield 'int to string' => [42, 'd', '42'];
        yield 'float to string' => [1.5, 'd', '1.5'];
        yield 'bool true to "1"' => [true, 'd', '1'];
        yield 'bool false to "" (handled, not default)' => [false, 'DEFAULT', ''];
        yield 'null returns default' => [null, 'fallback', 'fallback'];
        yield 'array returns default' => [['x'], 'fallback', 'fallback'];
        yield 'object returns default' => [new \stdClass(), 'fallback', 'fallback'];
    }

    public function testStringValueDefaultIsEmptyStringWhenOmitted(): void
    {
        self::assertSame('', TypeCoercion::stringValue(null));
    }

    public function testMethodsAreStaticAndPure(): void
    {
        // Calling repeatedly with the same input yields the same output (no state).
        self::assertSame(TypeCoercion::intValue('7'), TypeCoercion::intValue('7'));
        self::assertSame(TypeCoercion::floatValue('7.5'), TypeCoercion::floatValue('7.5'));
        self::assertSame(TypeCoercion::stringValue(7), TypeCoercion::stringValue(7));
    }
}
