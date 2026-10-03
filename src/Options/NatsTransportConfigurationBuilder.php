<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\Enum\DiscardPolicy;
use IDCT\NATS\JetStream\Enum\ReplayPolicy;
use IDCT\NATS\JetStream\Enum\RetentionPolicy;
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

    /** Longest stream description NATS accepts. */
    private const MAX_STREAM_DESCRIPTION_LENGTH = 4096;

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
        TransportOption::PING_AFTER_IDLE->value => 30,
        // The client's own default, so a transport that does not set it behaves as before the option existed.
        TransportOption::REQUEST_TIMEOUT->value => 10,
        TransportOption::MAX_ACK_PENDING->value => null,
        TransportOption::INACTIVE_THRESHOLD->value => null,
        TransportOption::REPLAY_POLICY->value => null,
        TransportOption::STREAM_MAX_AGE->value => 0,
        TransportOption::STREAM_MAX_BYTES->value => null,
        TransportOption::STREAM_MAX_MESSAGES->value => null,
        TransportOption::STREAM_MAX_MESSAGES_PER_SUBJECT->value => null,
        TransportOption::STREAM_MAX_MESSAGE_SIZE->value => null,
        TransportOption::STREAM_MAX_CONSUMERS->value => null,
        TransportOption::STREAM_STORAGE->value => StorageBackend::File->value,
        TransportOption::STREAM_REPLICAS->value => null,
        TransportOption::STREAM_RETENTION->value => null,
        TransportOption::STREAM_DISCARD->value => null,
        TransportOption::STREAM_DUPLICATE_WINDOW->value => null,
        TransportOption::STREAM_COMPRESSION->value => null,
        TransportOption::STREAM_DESCRIPTION->value => null,
        TransportOption::STREAM_DENY_DELETE->value => null,
        TransportOption::STREAM_DENY_PURGE->value => null,
        TransportOption::STREAM_ALLOW_DIRECT->value => null,
        TransportOption::STREAM_ALLOW_ROLLUP_HEADERS->value => null,
        TransportOption::RETRY_HANDLER->value => RetryHandler::SYMFONY->value,
        TransportOption::NAK_DELAY->value => 0,
        TransportOption::ACK_WAIT->value => null,
        TransportOption::MAX_DELIVER->value => null,
        TransportOption::BACKOFF->value => null,
        TransportOption::SCHEDULED_MESSAGES->value => false,
        TransportOption::ACK_SYNC->value => false,
        TransportOption::AUTO_SETUP->value => false,
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
            // How long a request waits for the server's reply: the publish acknowledgement of send(), an
            // ack_sync ACK, and the JetStream API calls of setup() and getMessageCount().
            requestTimeoutMs: max(1, TypeCoercion::secondsToMs($configuration[TransportOption::REQUEST_TIMEOUT->value] ?? null, 10.0)),
            pedantic: false,
            reconnectEnabled: false,
            tlsRequired: $this->toBool($configuration[TransportOption::TLS_REQUIRED->value]),
            tlsHandshakeFirst: $this->toBool($configuration[TransportOption::TLS_HANDSHAKE_FIRST->value]),
            tlsCaFile: $this->toNullableString($configuration[TransportOption::TLS_CA_FILE->value]),
            tlsCertFile: $this->toNullableString($configuration[TransportOption::TLS_CERT_FILE->value]),
            tlsKeyFile: $this->toNullableString($configuration[TransportOption::TLS_KEY_FILE->value]),
            tlsKeyPassphrase: $this->toNullableString($configuration[TransportOption::TLS_KEY_PASSPHRASE->value]),
            tlsPeerName: $this->toNullableString($configuration[TransportOption::TLS_PEER_NAME->value]),
            // Unlike the other boolean options, this one defaults to true, so a value the coercion does not
            // recognize has to keep it on: only false, 0, no or off turns peer verification off. Any other
            // value, an empty one included, used to turn it off without a word (#42).
            tlsVerifyPeer: TypeCoercion::boolValue($configuration[TransportOption::TLS_VERIFY_PEER->value], true),
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
            autoSetupEnabled: $this->toBool($configuration[TransportOption::AUTO_SETUP->value]),
        );
    }

    /**
     * Parses and validates DSN structure.
     *
     * @return array<string, mixed>
     */
    private function parseDsn(string $dsn): array
    {
        // A single gate for both structural failures: parse_url() returns false on a seriously
        // malformed DSN, and a hostless URL (e.g. "nats:///stream/topic") has no 'host'. Both are
        // equally invalid, so they share one throw - splitting them into two guards with the identical
        // message would be redundant (each masks the other, leaving an undetectable equivalent mutant).
        $components = parse_url($dsn);
        if (!is_array($components) || !isset($components['host'])) {
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
        $this->assertPositiveNumber($configuration, TransportOption::REQUEST_TIMEOUT);
        // 0 is meaningful here: it turns the check off.
        $this->assertNonNegativeNumber($configuration, TransportOption::PING_AFTER_IDLE);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_AGE, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_BYTES, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_MESSAGES, true);
        $this->assertNonNegativeNumber($configuration, TransportOption::STREAM_MAX_MESSAGES_PER_SUBJECT, true);
        // These three default to null, and null already means "unlimited" / "server default", so an
        // explicit 0 has no meaning to express: NATS reads 0 as unlimited for max_msg_size and
        // substitutes its own 2-minute default for a 0 duplicate window, both of which look like the
        // option was ignored. Requiring a positive value keeps the whole null-defaulted family
        // consistent with stream_max_consumers, max_ack_pending and max_deliver. stream_max_age is the
        // deliberate exception: it defaults to 0 and documents 0 as unlimited.
        $this->assertPositiveNumber($configuration, TransportOption::STREAM_MAX_MESSAGE_SIZE, true);
        $this->assertPositiveNumber($configuration, TransportOption::STREAM_MAX_CONSUMERS, true);
        $this->assertPositiveNumber($configuration, TransportOption::STREAM_REPLICAS, true);
        $this->assertPositiveNumber($configuration, TransportOption::STREAM_DUPLICATE_WINDOW, true);
        // max_msg_size is an int32 on the server, so a larger value fails inside setup() with a raw Go
        // unmarshal error instead of a configuration error.
        $this->assertNotExceedingInt32($configuration, TransportOption::STREAM_MAX_MESSAGE_SIZE);
        $this->assertStreamDescriptionLength($configuration);
        $this->assertTriStateBooleans($configuration);
        $this->assertNonNegativeNumber($configuration, TransportOption::NAK_DELAY);
        $this->assertPositiveNumber($configuration, TransportOption::ACK_WAIT);
        $this->assertPositiveNumber($configuration, TransportOption::MAX_DELIVER, true);
        $this->assertPositiveNumber($configuration, TransportOption::MAX_ACK_PENDING, true);
        $this->assertPositiveNumber($configuration, TransportOption::INACTIVE_THRESHOLD);
        $this->assertBackoff($configuration);
        $this->assertMaxDeliverExceedsBackoff($configuration);
        $this->assertDuplicateWindowNotExceedingMaxAge($configuration);
        $this->normalizeStorageBackend($configuration);
        $this->normalizeStreamRetention($configuration);
        $this->normalizeStreamDiscard($configuration);
        $this->normalizeStreamCompression($configuration);
        $this->normalizeReplayPolicy($configuration);

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
     * Validates and normalizes the optional stream retention policy, leaving it untouched when unset.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function normalizeStreamRetention(array &$configuration): void
    {
        $this->normalizeEnumOption(
            $configuration,
            TransportOption::STREAM_RETENTION,
            static fn (string $value): ?RetentionPolicy => RetentionPolicy::tryFrom($value),
            array_column(RetentionPolicy::cases(), 'value'),
        );
    }

    /**
     * Validates and normalizes the optional stream discard policy, leaving it untouched when unset.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function normalizeStreamDiscard(array &$configuration): void
    {
        $this->normalizeEnumOption(
            $configuration,
            TransportOption::STREAM_DISCARD,
            static fn (string $value): ?DiscardPolicy => DiscardPolicy::tryFrom($value),
            array_column(DiscardPolicy::cases(), 'value'),
        );
    }

    /**
     * Validates and normalizes the optional consumer replay policy, leaving it untouched when unset.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function normalizeReplayPolicy(array &$configuration): void
    {
        $this->normalizeEnumOption(
            $configuration,
            TransportOption::REPLAY_POLICY,
            static fn (string $value): ?ReplayPolicy => ReplayPolicy::tryFrom($value),
            array_column(ReplayPolicy::cases(), 'value'),
        );
    }

    /**
     * Validates and normalizes an optional enum-backed string option (case-insensitive input).
     *
     * When the option is unset it is left untouched. Otherwise the (lower-cased) value must map to a
     * case of the backing enum via $tryFrom, or an InvalidArgumentException naming the allowed values
     * is thrown - turning a typo into a clear configuration error instead of a server rejection.
     *
     * @param array<string, mixed>           $configuration Merged configuration array
     * @param TransportOption                $option        The option key to validate and normalize
     * @param callable(string): ?\BackedEnum $tryFrom       Enum resolver (e.g. RetentionPolicy::tryFrom(...))
     * @param list<string>                   $allowed       Allowed values, for the error message
     */
    private function normalizeEnumOption(array &$configuration, TransportOption $option, callable $tryFrom, array $allowed): void
    {
        $value = $configuration[$option->value] ?? null;
        if ($value === null) {
            return;
        }

        $raw = TypeCoercion::stringValue($value);
        $enum = $tryFrom(strtolower($raw));
        if ($enum === null) {
            throw new InvalidArgumentException(sprintf(
                "Invalid %s option '%s'. Allowed values are '%s'.",
                $option->value,
                $raw,
                implode("', '", $allowed),
            ));
        }

        $configuration[$option->value] = $enum->value;
    }

    /**
     * Validates and normalizes the optional stream compression algorithm, leaving it untouched when unset.
     *
     * The underlying client models compression as a plain string, so the allowed values live in the
     * local {@see StreamCompression} enum and validation goes through the same path as the client-backed
     * enums.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function normalizeStreamCompression(array &$configuration): void
    {
        $this->normalizeEnumOption(
            $configuration,
            TransportOption::STREAM_COMPRESSION,
            static fn (string $value): ?StreamCompression => StreamCompression::tryFrom($value),
            array_column(StreamCompression::cases(), 'value'),
        );
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
     * Validates that an option fits in a signed 32-bit integer.
     *
     * Some JetStream config fields are int32 on the server. A larger number is rejected by the Go JSON
     * decoder with "json: cannot unmarshal number ... into Go struct field ... of type int32", which
     * surfaces from setup() and says nothing about which option caused it.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     * @param TransportOption      $option        The option key to validate
     */
    private function assertNotExceedingInt32(array $configuration, TransportOption $option): void
    {
        $value = $configuration[$option->value] ?? null;
        if ($value === null) {
            return;
        }

        $number = $this->toNumber($value, $option);
        if ($number > 2147483647) {
            throw new InvalidArgumentException(sprintf(
                'The %s option must not exceed 2147483647: NATS stores it as a 32-bit integer.',
                $option->value,
            ));
        }
    }

    /**
     * Validates the stream description against the server's length limit.
     *
     * NATS caps a stream description at 4096 characters and rejects a longer one at setup time.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function assertStreamDescriptionLength(array $configuration): void
    {
        $value = $configuration[TransportOption::STREAM_DESCRIPTION->value] ?? null;
        if ($value === null) {
            return;
        }

        $description = TypeCoercion::stringValue($value);
        if (strlen($description) > self::MAX_STREAM_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The %s option must not exceed %d characters (got %d): NATS rejects longer descriptions.',
                TransportOption::STREAM_DESCRIPTION->value,
                self::MAX_STREAM_DESCRIPTION_LENGTH,
                strlen($description),
            ));
        }
    }

    /**
     * Validates the four tri-state stream policy flags.
     *
     * These differ from the always-on boolean options (scheduled_messages, ack_sync, auto_setup) in
     * that an unset value means "leave the server alone" while a set value is written to the stream.
     * That makes silent coercion dangerous: without this check `stream_deny_delete=maybe` would coerce
     * to false and be sent to the server as an explicit false, which reads as a deliberate instruction
     * rather than a typo. Unrecognized values are rejected instead, matching how the enum-backed
     * options behave.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function assertTriStateBooleans(array $configuration): void
    {
        $flags = [
            TransportOption::STREAM_DENY_DELETE,
            TransportOption::STREAM_DENY_PURGE,
            TransportOption::STREAM_ALLOW_DIRECT,
            TransportOption::STREAM_ALLOW_ROLLUP_HEADERS,
        ];

        foreach ($flags as $flag) {
            $value = $configuration[$flag->value] ?? null;
            if ($value === null || is_bool($value)) {
                continue;
            }

            // Round-trip through both defaults: a value the coercion policy actually recognizes gives
            // the same answer either way, an unrecognized one does not.
            if (TypeCoercion::boolValue($value, false) !== TypeCoercion::boolValue($value, true)) {
                throw new InvalidArgumentException(sprintf(
                    "Invalid %s option '%s'. Expected a boolean: 'true'/'false', '1'/'0', 'yes'/'no' or 'on'/'off'.",
                    $flag->value,
                    TypeCoercion::stringValue($value, '(non-scalar)'),
                ));
            }
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
     * Validates that the de-duplication window does not exceed the stream's max age.
     *
     * NATS rejects a stream whose duplicate_window is larger than a finite max_age
     * ("duplicates window can not be larger then max age"). A max_age of 0 means unlimited, so any
     * window is valid then. Validating here turns that into a clear configuration error instead of an
     * opaque server failure at {@see \IDCT\NatsMessenger\NatsTransport::setup()} time.
     *
     * @param array<string, mixed> $configuration Merged configuration array
     */
    private function assertDuplicateWindowNotExceedingMaxAge(array $configuration): void
    {
        $duplicateWindow = $configuration[TransportOption::STREAM_DUPLICATE_WINDOW->value] ?? null;
        if ($duplicateWindow === null) {
            return;
        }

        $maxAge = TypeCoercion::intValue($configuration[TransportOption::STREAM_MAX_AGE->value] ?? 0);
        if ($maxAge <= 0) {
            return;
        }

        $duplicateWindowValue = TypeCoercion::intValue($duplicateWindow);
        if ($duplicateWindowValue > $maxAge) {
            throw new InvalidArgumentException(sprintf(
                'The stream_duplicate_window option (%ds) must not exceed stream_max_age (%ds): NATS rejects a duplicate window larger than the stream max age.',
                $duplicateWindowValue,
                $maxAge,
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
        // is_numeric() is the single sufficient gate: it returns false for every non-numeric input -
        // arrays, bools, null and objects included - so a separate is_int/is_float/is_string guard
        // would be redundant (it could only throw the identical error for the same inputs). It also
        // narrows the value to int|float|numeric-string for the cast below.
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
     * Thin wrapper over {@see TypeCoercion::boolValue()} - kept so the numerous call sites in this
     * builder read fluently, while the coercion policy itself lives in one shared place.
     */
    private function toBool(mixed $value): bool
    {
        return TypeCoercion::boolValue($value);
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
