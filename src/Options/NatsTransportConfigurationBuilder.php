<?php

namespace IDCT\NatsMessenger\Options;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NatsMessenger\TypeCoercionTrait;
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
    use TypeCoercionTrait;

    /** Default NATS server port when not specified in DSN. */
    private const DEFAULT_NATS_PORT = 4222;

    /** Minimum DSN path length to contain both stream and topic (e.g. "/a/b"). */
    private const MIN_PATH_LENGTH = 4;

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
        TransportOption::STREAM_REPLICAS->value => 1,
        TransportOption::RETRY_HANDLER->value => RetryHandler::SYMFONY->value,
        TransportOption::SCHEDULED_MESSAGES->value => false,
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

        $scheme = $this->resolveTransportScheme($this->stringValue($components['scheme'] ?? 'nats', 'nats'));
        $host = $this->requiredString($components, 'host');
        $port = $this->intValue($components['port'] ?? self::DEFAULT_NATS_PORT, self::DEFAULT_NATS_PORT);
        $server = sprintf('%s://%s:%d', $scheme, $host, $port);

        $client = new NatsClient(new NatsOptions(
            servers: [$server],
            connectTimeoutMs: max(1, (int) round($this->floatValue($configuration[TransportOption::CONNECTION_TIMEOUT->value] ?? null, 1.0) * 1000)),
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
            username: $this->resolveUsername($components, $configuration),
            password: $this->resolvePassword($components, $configuration),
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

        $path = $components['path'];
        if (!is_string($path) || $path === '' || strlen($path) < self::MIN_PATH_LENGTH) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

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

        $retryHandler = RetryHandler::tryFrom($this->stringValue($configuration[TransportOption::RETRY_HANDLER->value] ?? RetryHandler::SYMFONY->value));
        if ($retryHandler === null) {
            $invalidRetryHandler = $this->stringValue($configuration[TransportOption::RETRY_HANDLER->value] ?? null);
            throw new InvalidArgumentException("Invalid retry_handler option '{$invalidRetryHandler}'. Allowed values are 'symfony' or 'nats'.");
        }

        $configuration[TransportOption::RETRY_HANDLER->value] = $retryHandler->value;

        $consumer = trim($this->stringValue($configuration[TransportOption::CONSUMER->value] ?? null));
        if ($consumer === '') {
            throw new InvalidArgumentException('The consumer option must be a non-empty string.');
        }

        $configuration[TransportOption::CONSUMER->value] = $consumer;

        $this->assertPositiveNumber($configuration, TransportOption::BATCHING, true);
        $this->assertPositiveNumber($configuration, TransportOption::MAX_BATCH_TIMEOUT, false);
        $this->assertPositiveNumber($configuration, TransportOption::CONNECTION_TIMEOUT, false);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_AGE);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_BYTES, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_MESSAGES, true);
        $this->assertPositiveNumber($configuration, TransportOption::STREAM_REPLICAS, true);

        return $configuration;
    }

    /**
     * Validates that an option is a positive number (and optionally an integer).
     *
     * @param array<string, mixed> $configuration Merged configuration array
     * @param TransportOption      $option        The option key to validate
     * @param bool                 $integerOnly   When true, also rejects non-integer values
     */
    private function assertPositiveNumber(array $configuration, TransportOption $option, bool $integerOnly): void
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
     * Resolves the NATS username from explicit option or DSN user component.
     *
     * Explicit option takes precedence. DSN user values are URL-decoded to handle
     * percent-encoded special characters (e.g. %40 for @).
     *
     * @param array<string, mixed> $components    Parsed DSN components
     * @param array<string, mixed> $configuration Merged option map
     */
    private function resolveUsername(array $components, array $configuration): ?string
    {
        $configuredUsername = $this->toNullableString($configuration[TransportOption::USERNAME->value]);
        if ($configuredUsername !== null) {
            return $configuredUsername;
        }

        $user = $components['user'] ?? null;

        return is_string($user) ? $this->toNullableString(urldecode($user)) : null;
    }

    /**
     * Resolves the NATS password from explicit option or DSN pass component.
     *
     * Explicit option takes precedence. DSN pass values are URL-decoded to handle
     * percent-encoded special characters.
     *
     * @param array<string, mixed> $components    Parsed DSN components
     * @param array<string, mixed> $configuration Merged option map
     */
    private function resolvePassword(array $components, array $configuration): ?string
    {
        $configuredPassword = $this->toNullableString($configuration[TransportOption::PASSWORD->value]);
        if ($configuredPassword !== null) {
            return $configuredPassword;
        }

        $pass = $components['pass'] ?? null;

        return is_string($pass) ? $this->toNullableString(urldecode($pass)) : null;
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
