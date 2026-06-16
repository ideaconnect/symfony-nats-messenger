<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\Enum\StorageBackend;
use IDCT\NatsMessenger\TypeCoercion;
use InvalidArgumentException;

/**
 * Parses a NATS JetStream DSN and merges transport options to produce an immutable
 * {@see NatsTransportConfiguration}.
 *
 * Responsible for:
 * - Parsing the DSN into host, port, path (stream/topic), query params, and credentials.
 * - Merging options with precedence: method params > DSN query params > defaults.
 * - Validating all option values (positive numbers, non-empty strings, etc.).
 * - Creating a pre-configured {@see NatsClient} with TLS and auth settings.
 *
 * DSN format: nats-jetstream://[user:pass@]host[:port]/stream/topic[?option=value&...]
 */
final class NatsTransportConfigurationBuilder
{
    /** Default NATS server port when not specified in DSN. */
    private const DEFAULT_NATS_PORT = 4222;

    /**
     * Default option values.
     *
     * Every recognized {@see TransportOption} must have an entry here. These defaults
     * are the lowest-priority layer in the merge: method options > DSN query > these defaults.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_OPTIONS = [
        TransportOption::CONSUMER->value => 'client',
        TransportOption::BATCHING->value => 1,
        TransportOption::MAX_BATCH_TIMEOUT->value => 1,
        TransportOption::CONNECTION_TIMEOUT->value => 1,
        TransportOption::STREAM_MAX_AGE->value => 0,
        TransportOption::STREAM_MAX_BYTES->value => null,
        TransportOption::STREAM_MAX_MESSAGES->value => null,
        TransportOption::STREAM_MAX_MESSAGES_PER_SUBJECT->value => null,
        TransportOption::STREAM_STORAGE->value => StorageBackend::File->value,
        TransportOption::STREAM_REPLICAS->value => null,
        TransportOption::RETRY_HANDLER->value => RetryHandler::SYMFONY->value,
        TransportOption::NAK_DELAY->value => 0,
        TransportOption::ACK_WAIT->value => null,
        TransportOption::MAX_DELIVER->value => null,
        TransportOption::BACKOFF->value => null,
        TransportOption::SCHEDULED_MESSAGES->value => false,
        TransportOption::ACK_SYNC->value => false,
        TransportOption::TLS_REQUIRED->value => false,
        TransportOption::TLS_HANDSHAKE_FIRST->value => false,
        TransportOption::TLS_CA_FILE->value => null,
        TransportOption::TLS_CERT_FILE->value => null,
        TransportOption::TLS_KEY_FILE->value => null,
        TransportOption::TLS_KEY_PASSPHRASE->value => null,
        TransportOption::TLS_PEER_NAME->value => null,
        TransportOption::TLS_VERIFY_PEER->value => true,
        TransportOption::TOKEN->value => null,
        TransportOption::JWT->value => null,
        TransportOption::NKEY->value => null,
        TransportOption::USERNAME->value => null,
        TransportOption::PASSWORD->value => null,
    ];

    /**
     * Builds an immutable transport configuration from a DSN and option overrides.
     *
     * Option precedence is: method options > DSN query params > defaults.
     *
     * @param array<string, mixed> $options
     */
    public function build(#[\SensitiveParameter] string $dsn, array $options = []): NatsTransportConfiguration
    {
        $components = $this->parseDsn($dsn);
        $configuration = $this->buildConfiguration($components, $options);

        [$streamName, $topic] = $this->parseStreamAndTopic($components);

        $scheme = $this->resolveTransportScheme(TypeCoercion::stringValue($components['scheme'] ?? 'nats', 'nats'));
        $host = $this->requiredString($components, 'host');
        $port = TypeCoercion::intValue($components['port'] ?? self::DEFAULT_NATS_PORT, self::DEFAULT_NATS_PORT);
        $server = sprintf('%s://%s:%d', $scheme, $host, $port);

        $client = new NatsClient(new NatsOptions(
            servers: [$server],
            connectTimeoutMs: max(1, TypeCoercion::secondsToMs($configuration[TransportOption::CONNECTION_TIMEOUT->value] ?? null, 1.0)),
            pedantic: false,
            reconnectEnabled: false,
            tlsRequired: $this->toBool($configuration[TransportOption::TLS_REQUIRED->value]),
            tlsHandshakeFirst: $this->toBool($configuration[TransportOption::TLS_HANDSHAKE_FIRST->value]),
            tlsCaFile: $this->toNullableString($configuration[TransportOption::TLS_CA_FILE->value]),
            tlsCertFile: $this->toNullableString($configuration[TransportOption::TLS_CERT_FILE->value]),
            tlsKeyFile: $this->toNullableString($configuration[TransportOption::TLS_KEY_FILE->value]),
            tlsKeyPassphrase: $this->toNullableString($configuration[TransportOption::TLS_KEY_PASSPHRASE->value]),
            tlsPeerName: $this->toNullableString($configuration[TransportOption::TLS_PEER_NAME->value]),
            tlsVerifyPeer: $this->toBool($configuration[TransportOption::TLS_VERIFY_PEER->value]),
            token: $this->toNullableString($configuration[TransportOption::TOKEN->value]),
            username: $this->resolveCredential($components, $configuration, TransportOption::USERNAME, 'user'),
            password: $this->resolveCredential($components, $configuration, TransportOption::PASSWORD, 'pass'),
            jwt: $this->toNullableString($configuration[TransportOption::JWT->value]),
            nkey: $this->toNullableString($configuration[TransportOption::NKEY->value]),
        ));

        return new NatsTransportConfiguration(
            topic: $topic,
            streamName: $streamName,
            client: $client,
            options: $configuration,
            natsRetryHandlerEnabled: $configuration[TransportOption::RETRY_HANDLER->value] === RetryHandler::NATS->value,
            scheduledMessagesEnabled: $this->toBool($configuration[TransportOption::SCHEDULED_MESSAGES->value]),
            ackSyncEnabled: $this->toBool($configuration[TransportOption::ACK_SYNC->value]),
        );
    }

    /**
     * Parses and validates DSN structure.
     *
     * @return array<string, mixed>
     */
    private function parseDsn(string $dsn): array
    {
        if (false === $components = parse_url($dsn)) {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        if (!isset($components['host'])) {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        return $components;
    }

    /**
     * Extracts stream and topic from DSN path (/stream/topic).
     *
     * @param array<string, mixed> $components
     * @return array{0: string, 1: string}
     */
    private function parseStreamAndTopic(array $components): array
    {
        if (!isset($components['path'])) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        // A valid path is exactly two non-empty slash-separated tokens (/stream/topic). Coercing a
        // non-string/empty path to '' lets this single check reject every malformed path - missing,
        // too few or too many segments, or an empty token.
        $path = is_string($components['path']) ? $components['path'] : '';
        $pathParts = explode('/', trim($path, '/'));
        if (count($pathParts) !== 2 || $pathParts[0] === '' || $pathParts[1] === '') {
            throw new InvalidArgumentException('NATS DSN must contain both stream name and topic name (format: /stream/topic).');
        }

        $this->validateNatsName($pathParts[0], 'stream');
        $this->validateNatsName($pathParts[1], 'topic');

        return [$pathParts[0], $pathParts[1]];
    }

    /**
     * Validates stream and topic path segments.
     *
     * Stream names remain strict identifiers. Topic names allow standard dotted NATS
     * subject tokens, but still reject wildcards, spaces, and empty tokens.
     *
     * @param string $name  The name to validate
     * @param string $label Human-readable label for error messages ('stream' or 'topic')
     *
     * @throws InvalidArgumentException If the name contains disallowed characters
     */
    private function validateNatsName(string $name, string $label): void
    {
        $pattern = $label === 'topic'
            ? '/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/'
            : '/^[a-zA-Z0-9_-]+$/';

        if (preg_match($pattern, $name) !== 1) {
            $allowedCharacters = $label === 'topic'
                ? 'Only dot-separated subject tokens containing alphanumeric characters, hyphens, and underscores are allowed.'
                : 'Only alphanumeric characters, hyphens, and underscores are allowed.';

            throw new InvalidArgumentException(sprintf(
                'NATS %s name "%s" contains invalid characters. %s',
                $label,
                $name,
                $allowedCharacters,
            ));
        }
    }

    /**
     * Merges and validates final runtime options.
     *
     * Merge precedence: $options (method params) > $query (DSN query string) > DEFAULT_OPTIONS.
     * PHP's array union operator (+) keeps the first occurrence, so placing $options first
     * ensures method-level overrides win.
     *
     * @param array<string, mixed> $components Parsed DSN components from parse_url()
     * @param array<string, mixed> $options    Method-level option overrides
     * @return array<string, mixed>            Fully merged and validated configuration
     */
    private function buildConfiguration(array $components, array $options): array
    {
        $query = [];
        if (isset($components['query']) && is_string($components['query'])) {
            /** @var array<string, mixed> $query */
            parse_str($components['query'], $query);
        }

        /** @var array<string, mixed> $configuration */
        $configuration = $options + $query + self::DEFAULT_OPTIONS;

        $retryHandler = RetryHandler::tryFrom(TypeCoercion::stringValue($configuration[TransportOption::RETRY_HANDLER->value] ?? RetryHandler::SYMFONY->value));
        if ($retryHandler === null) {
            $invalidRetryHandler = TypeCoercion::stringValue($configuration[TransportOption::RETRY_HANDLER->value] ?? null);
            throw new InvalidArgumentException("Invalid retry_handler option '{$invalidRetryHandler}'. Allowed values are 'symfony' or 'nats'.");
        }

        $configuration[TransportOption::RETRY_HANDLER->value] = $retryHandler->value;

        $consumer = trim(TypeCoercion::stringValue($configuration[TransportOption::CONSUMER->value] ?? null));
        if ($consumer === '') {
            throw new InvalidArgumentException('The consumer option must be a non-empty string.');
        }

        $configuration[TransportOption::CONSUMER->value] = $consumer;

        $this->assertPositiveNumber($configuration, TransportOption::BATCHING, true);
        $this->assertPositiveNumber($configuration, TransportOption::MAX_BATCH_TIMEOUT);
        $this->assertPositiveNumber($configuration, TransportOption::CONNECTION_TIMEOUT);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_AGE, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_BYTES, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_MESSAGES, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_MESSAGES_PER_SUBJECT, true);
        $this->assertPositiveNumber($configuration, TransportOption::STREAM_REPLICAS, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::NAK_DELAY);
        $this->assertPositiveNumber($configuration, TransportOption::ACK_WAIT);
        $this->assertPositiveNumber($configuration, TransportOption::MAX_DELIVER, true);
        $this->assertBackoff($configuration);
        $this->assertMaxDeliverExceedsBackoff($configuration);
        $this->normalizeStorageBackend($configuration);

        return $configuration;
    }

    /**
     * Validates and normalizes the configured stream storage backend.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function normalizeStorageBackend(array &$configuration): void
    {
        $storage = TypeCoercion::stringValue(
            $configuration[TransportOption::STREAM_STORAGE->value] ?? StorageBackend::File->value,
            StorageBackend::File->value,
        );

        $storageBackend = StorageBackend::tryFrom(strtolower($storage));
        if ($storageBackend === null) {
            throw new InvalidArgumentException(sprintf(
                "Invalid stream_storage option '%s'. Allowed values are 'file' or 'memory'.",
                $storage,
            ));
        }

        $configuration[TransportOption::STREAM_STORAGE->value] = $storageBackend->value;
    }

    /**
     * Validates that an option is a positive number (and optionally an integer).
     *
     * @param array<string, mixed> $configuration Merged configuration array
     * @param TransportOption      $option        The option key to validate
     * @param bool                 $integerOnly   When true, also rejects non-integer values
     */
    private function assertPositiveNumber(array $configuration, TransportOption $option, bool $integerOnly = false): void
    {
        $value = $configuration[$option->value] ?? null;
        if ($value === null) {
            return;
        }

        $number = $this->toNumber($value, $option);
        if ($number <= 0 || ($integerOnly && floor($number) !== $number)) {
            throw new InvalidArgumentException(sprintf('The %s option must be a positive%s value.', $option->value, $integerOnly ? ' integer' : ''));
        }
    }

    /**
     * Validates that an option is a non-negative number (and optionally an integer).
     *
     * @param array<string, mixed> $configuration Merged configuration array
     * @param TransportOption      $option        The option key to validate
     * @param bool                 $integerOnly   When true, also rejects non-integer values
     */
    private function assertNonNegativeNumber(array $configuration, TransportOption $option, bool $integerOnly = false): void
    {
        $value = $configuration[$option->value] ?? null;
        if ($value === null) {
            return;
        }

        $number = $this->toNumber($value, $option);
        if ($number < 0 || ($integerOnly && floor($number) !== $number)) {
            throw new InvalidArgumentException(sprintf('The %s option must be a non-negative%s value.', $option->value, $integerOnly ? ' integer' : ''));
        }
    }

    /**
     * Validates the backoff option: a non-empty list of non-negative numbers (seconds), when set.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function assertBackoff(array $configuration): void
    {
        $backoff = $configuration[TransportOption::BACKOFF->value] ?? null;
        if ($backoff === null) {
            return;
        }

        if (!is_array($backoff) || $backoff === []) {
            throw new InvalidArgumentException('The backoff option must be a non-empty list of non-negative numbers (seconds).');
        }

        foreach ($backoff as $value) {
            if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || (float) $value < 0) {
                throw new InvalidArgumentException('The backoff option must be a list of non-negative numbers (seconds).');
            }
        }
    }

    /**
     * Validates that max_deliver leaves room for the backoff schedule.
     *
     * When both options are set, NATS requires max_deliver to be strictly greater than the number
     * of backoff entries (it must allow at least one delivery beyond the backoff schedule) and
     * rejects the consumer otherwise. Validating here turns that into a clear configuration error
     * instead of an opaque server failure at {@see \IDCT\NatsMessenger\NatsTransport::setup()} time.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function assertMaxDeliverExceedsBackoff(array $configuration): void
    {
        $backoff = $configuration[TransportOption::BACKOFF->value] ?? null;
        $maxDeliver = $configuration[TransportOption::MAX_DELIVER->value] ?? null;

        if (!is_array($backoff) || $backoff === [] || $maxDeliver === null) {
            return;
        }

        $maxDeliverValue = TypeCoercion::intValue($maxDeliver);
        $backoffCount = count($backoff);
        if ($maxDeliverValue <= $backoffCount) {
            throw new InvalidArgumentException(sprintf(
                'The max_deliver option (%d) must be greater than the number of backoff entries (%d): NATS requires room for at least one delivery beyond the backoff schedule.',
                $maxDeliverValue,
                $backoffCount,
            ));
        }
    }

    /**
     * Converts a raw option value to float for numeric validation.
     *
     * @throws InvalidArgumentException If the value is not numeric
     */
    private function toNumber(mixed $value, TransportOption $option): float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s option must be numeric.', $option->value));
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('The %s option must be numeric.', $option->value));
        }

        return (float) $value;
    }

    /**
     * Resolves wire protocol for NATS client server URLs.
     *
     * Maps DSN scheme suffixes (+tls) to the "tls" protocol; everything else
     * uses plain "nats".
     */
    private function resolveTransportScheme(string $dsnScheme): string
    {
        $normalizedScheme = strtolower($dsnScheme);

        if ($normalizedScheme === 'tls' || str_ends_with($normalizedScheme, '+tls')) {
            return 'tls';
        }

        return 'nats';
    }

    /**
     * Converts a mixed value to boolean.
     *
     * Recognizes bool, int (0 = false), and string values ('1', 'true', 'yes', 'on').
     * Returns false for all other types.
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Converts a mixed value to a nullable string.
     *
     * Returns null for actual nulls, non-scalar types, and empty/whitespace-only strings.
     * Trims whitespace from valid string values.
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Resolves a NATS credential (username or password) from an explicit option or the DSN.
     *
     * The explicit option takes precedence. The DSN component is decoded with rawurldecode()
     * (RFC 3986 userinfo semantics): %XX escapes are decoded (e.g. %40 → @, %2B → +) while a
     * literal '+' is preserved. urldecode() must NOT be used here - it would turn a literal '+'
     * in a username/password into a space and silently corrupt the credential.
     *
     * @param array<string, mixed> $components    Parsed DSN components
     * @param array<string, mixed> $configuration Merged option map
     * @param TransportOption       $option        Explicit-option key (USERNAME or PASSWORD)
     * @param string                $componentKey  DSN component key ('user' or 'pass')
     */
    private function resolveCredential(array $components, array $configuration, TransportOption $option, string $componentKey): ?string
    {
        $configured = $this->normalizeCredential($configuration[$option->value] ?? null);
        if ($configured !== null) {
            return $configured;
        }

        $value = $components[$componentKey] ?? null;

        return is_string($value) ? $this->normalizeCredential(rawurldecode($value)) : null;
    }

    /**
     * Normalizes a credential value: maps null, non-scalar, and the empty string to null.
     *
     * Unlike toNullableString() this does NOT trim, because leading or trailing whitespace can be a
     * significant part of a username or password and trimming it would silently corrupt the credential.
     */
    private function normalizeCredential(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    /**
     * Extracts a required non-empty string from DSN components.
     *
     * @param array<string, mixed> $components Parsed DSN components
     * @param string               $key        Component key (e.g. 'host')
     *
     * @throws InvalidArgumentException If the key is missing or empty
     */
    private function requiredString(array $components, string $key): string
    {
        $value = $components[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        return $value;
    }
}
