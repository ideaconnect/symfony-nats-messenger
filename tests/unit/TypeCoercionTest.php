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

    #[DataProvider('secondsToMsProvider')]
    public function testSecondsToMs(mixed $value, float $default, int $expected): void
    {
        self::assertSame($expected, TypeCoercion::secondsToMs($value, $default));
    }

    /**
     * @return iterable<string, array{mixed, float, int}>
     */
    public static function secondsToMsProvider(): iterable
    {
        yield 'whole seconds int' => [2, 0.0, 2000];
        yield 'fractional seconds float' => [2.5, 0.0, 2500];
        yield 'numeric string seconds' => ['1.5', 0.0, 1500];
        yield 'sub-millisecond rounds to nearest ms' => [0.0015, 0.0, 2];
        yield 'under half a millisecond rounds down' => [0.0004, 0.0, 0];
        yield 'under half a millisecond above a whole second rounds down' => [1.0004, 0.0, 1000];
        yield 'zero seconds' => [0, 9.0, 0];
        yield 'non-numeric falls back to default seconds' => ['nope', 1.0, 1000];
        yield 'null falls back to default seconds' => [null, 0.5, 500];
        yield 'array falls back to default seconds' => [['x'], 0.0, 0];
    }

    public function testSecondsToMsDefaultIsZeroWhenOmitted(): void
    {
        self::assertSame(0, TypeCoercion::secondsToMs('not-a-number'));
    }

    #[DataProvider('boolValueProvider')]
    public function testBoolValue(mixed $value, bool $default, bool $expected): void
    {
        self::assertSame($expected, TypeCoercion::boolValue($value, $default));
    }

    /**
     * @return iterable<string, array{mixed, bool, bool}>
     */
    public static function boolValueProvider(): iterable
    {
        yield 'true passes through' => [true, false, true];
        yield 'false passes through' => [false, true, false];
        yield 'non-zero int is true' => [1, false, true];
        yield 'negative int is true' => [-3, false, true];
        yield 'zero int is false' => [0, true, false];
        yield 'truthy token 1' => ['1', false, true];
        yield 'truthy token true' => ['true', false, true];
        yield 'truthy token yes' => ['yes', false, true];
        yield 'truthy token on' => ['on', false, true];
        yield 'truthy token is case-insensitive' => ['TRUE', false, true];
        yield 'falsy token 0' => ['0', true, false];
        yield 'falsy token false' => ['false', true, false];
        yield 'falsy token no' => ['no', true, false];
        yield 'falsy token off' => ['off', true, false];
        yield 'falsy token is case-insensitive' => ['Off', true, false];
        // Neither list matches, so the caller's default decides.
        yield 'unrecognized string falls back to default false' => ['maybe', false, false];
        yield 'unrecognized string falls back to default true' => ['maybe', true, true];
        yield 'empty string falls back to default' => ['', true, true];
        yield 'null falls back to default' => [null, true, true];
        yield 'array falls back to default' => [['x'], true, true];
        yield 'object falls back to default' => [new \stdClass(), false, false];
    }

    public function testBoolValueDefaultIsFalseWhenOmitted(): void
    {
        self::assertFalse(TypeCoercion::boolValue('maybe'));
        self::assertFalse(TypeCoercion::boolValue(null));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('stringListValueProvider')]
    public function testStringListValue(mixed $value, array $expected): void
    {
        self::assertSame($expected, TypeCoercion::stringListValue($value));
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function stringListValueProvider(): iterable
    {
        yield 'list of strings passes through' => [['a', 'b'], ['a', 'b']];
        yield 'elements are trimmed' => [[' a ', "\tb"], ['a', 'b']];
        yield 'empty and blank elements are dropped' => [['a', '', '  ', 'b'], ['a', 'b']];
        yield 'duplicates are dropped, first occurrence wins' => [['a', 'b', 'a'], ['a', 'b']];
        yield 'integer elements are stringified' => [[1, 'a', 2], ['1', 'a', '2']];
        yield 'non-scalar and non-string elements are dropped' => [['a', ['nested'], true, 1.5, null, 'b'], ['a', 'b']];
        yield 'associative array keeps the values as a list' => [['x' => 'a', 'y' => 'b'], ['a', 'b']];
        yield 'comma-separated string is split and trimmed' => ['a, b ,c', ['a', 'b', 'c']];
        yield 'comma-separated string drops empty segments' => [',a,,b,', ['a', 'b']];
        yield 'single string without commas' => ['a', ['a']];
        yield 'integer becomes a single-element list' => [7, ['7']];
        yield 'empty string yields an empty list' => ['', []];
        yield 'null yields an empty list' => [null, []];
        yield 'bool yields an empty list' => [true, []];
        yield 'float yields an empty list' => [1.5, []];
        yield 'object yields an empty list' => [new \stdClass(), []];
    }

    public function testMethodsAreStaticAndPure(): void
    {
        // Calling repeatedly with the same input yields the same output (no state).
        self::assertSame(TypeCoercion::intValue('7'), TypeCoercion::intValue('7'));
        self::assertSame(TypeCoercion::stringListValue('a,b'), TypeCoercion::stringListValue('a,b'));
        self::assertSame(TypeCoercion::floatValue('7.5'), TypeCoercion::floatValue('7.5'));
        self::assertSame(TypeCoercion::stringValue(7), TypeCoercion::stringValue(7));
        self::assertSame(TypeCoercion::boolValue('yes'), TypeCoercion::boolValue('yes'));
        self::assertSame(TypeCoercion::secondsToMs('2.5'), TypeCoercion::secondsToMs('2.5'));
    }
}
