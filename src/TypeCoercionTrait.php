<?php

namespace IDCT\NatsMessenger;

/**
 * Provides safe type coercion helpers for mixed values from DSN parsing and JetStream responses.
 *
 * NATS transport options arrive as strings (from DSN query params or YAML config) and JetStream
 * API responses use loosely typed JSON. This trait centralizes the casting logic so that
 * every consumer site handles type diversity consistently.
 *
 * Used by {@see NatsTransport}, {@see NatsTransportConfiguration}, and
 * {@see NatsTransportConfigurationBuilder}.
 */
trait TypeCoercionTrait
{
    /**
     * Casts a mixed value to float.
     *
     * Accepts float, int, and numeric strings. Returns $default for all other types.
     */
    private function floatValue(mixed $value, float $default = 0.0): float
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
     * Casts a mixed value to int.
     *
     * Accepts int, float (truncated), and numeric strings. Returns $default for all other types.
     */
    private function intValue(mixed $value, int $default = 0): int
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
     * Casts a mixed value to string.
     *
     * Accepts strings, ints, floats, and bools. Returns $default for all other types
     * (arrays, objects, null).
     */
    private function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }
}
