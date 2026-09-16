<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

/**
 * Safe, deterministic coercion of mixed values to scalars.
 *
 * NATS transport options arrive with source-dependent types: a value from a DSN query string is
 * always a string (e.g. `?batching=5`), while the same option from YAML config is already typed.
 * JetStream API responses are decoded from loosely typed JSON and exposed as `array<string, mixed>`.
 * These helpers centralize the casting policy so every call site narrows `mixed` the same way.
 *
 * All methods are pure: given the same input they return the same output, with no side effects. When
 * a value cannot be meaningfully coerced (e.g. an array passed where a number is expected) the
 * provided default is returned rather than triggering a `TypeError`. Callers that must reject invalid
 * input (such as {@see Options\NatsTransportConfigurationBuilder}) validate separately before relying
 * on the fallback.
 */
final class TypeCoercion
{
    /**
     * Coerces a mixed value to float.
     *
     * Accepts float, int, and numeric strings. Returns $default for all other types.
     */
    public static function floatValue(mixed $value, float $default = 0.0): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * Coerces a mixed value to int.
     *
     * Accepts int, float (truncated), and numeric strings. Returns $default for all other types.
     */
    public static function intValue(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Coerces a mixed value to string.
     *
     * Accepts strings, ints, floats, and bools. Returns $default for all other types
     * (arrays, objects, null).
     */
    public static function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Coerces a mixed value to bool.
     *
     * Recognizes bool as-is, int (0 = false, non-zero = true), the truthy string tokens '1', 'true',
     * 'yes', 'on' and the falsy string tokens '0', 'false', 'no', 'off' (all case-insensitive).
     * Returns $default for everything else: arrays, objects, null, and strings that match neither list.
     *
     * The default default is false, so an unrecognized value never accidentally reads as true. Callers
     * that need an option to stay enabled unless it is explicitly turned off pass $default = true.
     */
    public static function boolValue(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower($value);

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Converts a mixed seconds value to whole milliseconds.
     *
     * Applies the same coercion policy as {@see floatValue()} (numeric strings/ints/floats accepted,
     * otherwise $default) and rounds the result to an integer. Centralizes the seconds→milliseconds
     * rule shared by the transport's timeout/delay options; callers apply their own min-clamp.
     */
    public static function secondsToMs(mixed $seconds, float $default = 0.0): int
    {
        return (int) round(self::floatValue($seconds, $default) * 1000);
    }

    /**
     * Coerces a list-like option value to a de-duplicated list of trimmed, non-empty strings.
     *
     * Accepts a PHP array (a YAML list, or `option[]=a&option[]=b` from a DSN query string) whose
     * elements are strings or integers, or a single comma-separated string (`option=a,b` from a DSN
     * query string). Elements of any other type, and elements that are empty once trimmed, are
     * dropped. A value that is neither an array nor a string/integer yields an empty list.
     *
     * @return list<string>
     */
    public static function stringListValue(mixed $value): array
    {
        if (is_string($value) || is_int($value)) {
            $value = explode(',', (string) $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $element) {
            if (!is_string($element) && !is_int($element)) {
                continue;
            }

            $normalized = trim((string) $element);
            if ($normalized === '' || in_array($normalized, $list, true)) {
                continue;
            }

            $list[] = $normalized;
        }

        return $list;
    }
}
