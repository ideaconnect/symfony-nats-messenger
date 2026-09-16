<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\Enum\DiscardPolicy;
use IDCT\NATS\JetStream\Enum\ReplayPolicy;
use IDCT\NATS\JetStream\Enum\RetentionPolicy;
use IDCT\NATS\JetStream\Enum\StorageBackend;
use IDCT\NatsMessenger\TypeCoercion;

/**
 * Immutable, normalized transport configuration built from DSN and options.
 *
 * Created by {@see NatsTransportConfigurationBuilder::build()} after merging DSN query params,
 * method-level option overrides, and defaults. All accessor methods apply normalization
 * (clamping, unit conversion) so callers always receive valid runtime values.
 *
 * @see NatsTransportConfigurationBuilder Builds instances of this class.
 * @see TransportOption                  Enum of all recognized option keys.
 */
final readonly class NatsTransportConfiguration
{
    /**
     * @param string               $topic                   JetStream subject name
     * @param string               $streamName              JetStream stream name backing the subject
     * @param NatsClient           $client                  Pre-configured NATS client instance
     * @param array<string, mixed> $options                 Merged option map (method > DSN query > defaults)
     * @param bool                 $natsRetryHandlerEnabled True when retry handling is delegated to NATS (NAK mode)
     * @param bool                 $scheduledMessagesEnabled True when delayed/scheduled message publishing is enabled
     * @param bool                 $ackSyncEnabled          True when acknowledgements should wait for server confirmation (double-ack)
     * @param bool                 $autoSetupEnabled        True when the transport should provision the stream/consumer on first use
     * @param bool                 $deduplicationEnabled    True when every published message gets an id JetStream deduplicates it by
     */
    public function __construct(
        public string $topic,
        public string $streamName,
        public NatsClient $client,
        private array $options,
        private bool $natsRetryHandlerEnabled,
        private bool $scheduledMessagesEnabled = false,
        private bool $ackSyncEnabled = false,
        private bool $autoSetupEnabled = false,
        private bool $deduplicationEnabled = false,
    ) {
    }

    /**
     * Returns the configured durable consumer name.
     *
     * Defaults to 'client' if not specified in options.
     */
    public function consumer(): string
    {
        return $this->stringOption(TransportOption::CONSUMER, 'client');
    }

    /**
     * Returns normalized fetch batch size (minimum 1).
     *
     * Controls how many messages are requested per pull from JetStream.
     */
    public function batching(): int
    {
        return max(1, $this->intOption(TransportOption::BATCHING, 1));
    }

    /**
     * Returns normalized pull timeout in milliseconds (minimum 1ms).
     *
     * The source value (max_batch_timeout) is in seconds (float); this method
     * converts to integer milliseconds and clamps to at least 1ms.
     */
    public function maxBatchTimeoutMs(): int
    {
        return max(1, TypeCoercion::secondsToMs($this->options[TransportOption::MAX_BATCH_TIMEOUT->value] ?? null, 1.0));
    }

    /**
     * Returns the dial timeout in seconds (minimum 1ms), the same value the client gets as its
     * connectTimeoutMs.
     *
     * The transport also uses it to bound the PING it sends to a connection that sat idle
     * ({@see pingAfterIdleSeconds()}): a server that does not answer within the time a fresh dial may take
     * is treated as gone.
     */
    public function connectionTimeoutSeconds(): float
    {
        return max(1, TypeCoercion::secondsToMs($this->options[TransportOption::CONNECTION_TIMEOUT->value] ?? null, 1.0)) / 1000;
    }

    /**
     * Returns how long, in seconds, the connection may go unused before the next operation checks it with a
     * PING; 0 turns the check off.
     *
     * A server drops a client that stops answering its pings, and a PHP process answers them only while it is
     * inside a transport call; load balancers and NAT gateways drop idle connections too. Neither shows until
     * the next write, which then fails. Checking first lets the transport dial again before it uses the
     * connection, instead of losing that operation.
     */
    public function pingAfterIdleSeconds(): float
    {
        return max(0.0, TypeCoercion::floatValue($this->options[TransportOption::PING_AFTER_IDLE->value] ?? null, 30.0));
    }

    /**
     * Returns whether the NATS client re-dials on its own after the connection drops (default: false).
     *
     * The flag is applied to the client by {@see NatsTransportConfigurationBuilder}; this accessor
     * only reports the configured value.
     */
    public function isReconnectEnabled(): bool
    {
        return TypeCoercion::boolValue($this->options[TransportOption::RECONNECT->value] ?? null);
    }

    /**
     * Returns the re-dial attempts per outage, or null to keep the NATS client's own default.
     */
    public function maxReconnectAttempts(): ?int
    {
        return $this->nullableIntOption(TransportOption::MAX_RECONNECT_ATTEMPTS);
    }

    /**
     * Returns stream max age in seconds (0 means unlimited).
     *
     * Used by {@see NatsTransport::setup()} when creating/updating the stream.
     * Converted to nanoseconds before being sent to JetStream.
     */
    public function streamMaxAgeSeconds(): int
    {
        return max(0, $this->intOption(TransportOption::STREAM_MAX_AGE, 0));
    }

    /**
     * Returns stream max bytes, or null for unlimited.
     *
     * When set, JetStream will discard oldest messages once the stream exceeds this byte limit.
     */
    public function streamMaxBytes(): ?int
    {
        return $this->nullableIntOption(TransportOption::STREAM_MAX_BYTES);
    }

    /**
     * Returns stream max messages, or null for unlimited.
     *
     * When set, JetStream will discard oldest messages once the stream exceeds this count.
     */
    public function streamMaxMessages(): ?int
    {
        return $this->nullableIntOption(TransportOption::STREAM_MAX_MESSAGES);
    }

    /**
     * Returns stream max messages per subject, or null for unlimited.
     *
     * When set, JetStream limits the number of retained messages for each individual subject.
     */
    public function streamMaxMessagesPerSubject(): ?int
    {
        return $this->nullableIntOption(TransportOption::STREAM_MAX_MESSAGES_PER_SUBJECT);
    }

    /**
     * Returns the configured stream storage backend.
     */
    public function streamStorage(): StorageBackend
    {
        $storage = $this->stringOption(TransportOption::STREAM_STORAGE, StorageBackend::File->value);

        return StorageBackend::from($storage);
    }

    /**
     * Returns configured stream replica count.
     *
     * Controls the number of JetStream stream replicas for high availability.
     * Defaults to 1 (no replication). Only meaningful in clustered NATS deployments.
     */
    public function streamReplicas(): int
    {
        return max(1, $this->intOption(TransportOption::STREAM_REPLICAS, 1));
    }

    /**
     * Returns true when stream_replicas was explicitly configured (as opposed to defaulted).
     *
     * Lets {@see NatsTransport::setup()} decide, on the update path, whether to write the managed
     * replica count or preserve the existing server value - so a stream created with more replicas
     * (e.g. in a cluster) is not silently downscaled when setup() runs without the option set.
     */
    public function hasExplicitStreamReplicas(): bool
    {
        return ($this->options[TransportOption::STREAM_REPLICAS->value] ?? null) !== null;
    }

    /**
     * Returns the configured stream retention policy, or null to use the JetStream default (limits).
     *
     * Retention is fixed at stream creation: NATS rejects changing it on an existing stream, so
     * {@see NatsTransport::setup()} writes it only when the stream is created and preserves the server
     * value on update. Changing retention on a live stream requires recreating it.
     */
    public function streamRetention(): ?RetentionPolicy
    {
        $value = $this->options[TransportOption::STREAM_RETENTION->value] ?? null;

        return $value === null ? null : RetentionPolicy::from(TypeCoercion::stringValue($value));
    }

    /**
     * Returns the configured stream discard policy, or null to use the JetStream default (old).
     *
     * Determines what JetStream does when a stream limit is reached: discard the oldest messages
     * ({@see DiscardPolicy::Old}) or reject new ones ({@see DiscardPolicy::New}).
     */
    public function streamDiscard(): ?DiscardPolicy
    {
        $value = $this->options[TransportOption::STREAM_DISCARD->value] ?? null;

        return $value === null ? null : DiscardPolicy::from(TypeCoercion::stringValue($value));
    }

    /**
     * Returns the stream de-duplication window in seconds, or null to use the JetStream default.
     *
     * JetStream ignores a duplicate publish (same Nats-Msg-Id) seen within this window. Validated at
     * build time to never exceed {@see streamMaxAgeSeconds()} when both are set.
     */
    public function streamDuplicateWindowSeconds(): ?int
    {
        return $this->nullableIntOption(TransportOption::STREAM_DUPLICATE_WINDOW);
    }

    /**
     * Returns the maximum size of a single message in bytes, or null for unlimited.
     */
    public function streamMaxMessageSize(): ?int
    {
        return $this->nullableIntOption(TransportOption::STREAM_MAX_MESSAGE_SIZE);
    }

    /**
     * Returns the maximum number of consumers allowed on the stream, or null for unlimited.
     */
    public function streamMaxConsumers(): ?int
    {
        return $this->nullableIntOption(TransportOption::STREAM_MAX_CONSUMERS);
    }

    /**
     * Returns the configured stream compression algorithm, or null to leave the server default.
     */
    public function streamCompression(): ?StreamCompression
    {
        $value = $this->options[TransportOption::STREAM_COMPRESSION->value] ?? null;

        return $value === null ? null : StreamCompression::from(TypeCoercion::stringValue($value));
    }

    /**
     * Returns the human-readable stream description, or null when unset.
     */
    public function streamDescription(): ?string
    {
        $value = $this->options[TransportOption::STREAM_DESCRIPTION->value] ?? null;

        return $value === null ? null : TypeCoercion::stringValue($value);
    }

    /**
     * Returns whether stream message deletion is denied, or null to leave the server default untouched.
     */
    public function streamDenyDelete(): ?bool
    {
        return $this->nullableBoolOption(TransportOption::STREAM_DENY_DELETE);
    }

    /**
     * Returns whether stream purge is denied, or null to leave the server default untouched.
     */
    public function streamDenyPurge(): ?bool
    {
        return $this->nullableBoolOption(TransportOption::STREAM_DENY_PURGE);
    }

    /**
     * Returns whether direct get access is allowed, or null to leave the server default untouched.
     */
    public function streamAllowDirect(): ?bool
    {
        return $this->nullableBoolOption(TransportOption::STREAM_ALLOW_DIRECT);
    }

    /**
     * Returns whether rollup headers are allowed, or null to leave the server default untouched.
     */
    public function streamAllowRollupHeaders(): ?bool
    {
        return $this->nullableBoolOption(TransportOption::STREAM_ALLOW_ROLLUP_HEADERS);
    }

    /**
     * Returns the JetStream cluster the stream is pinned to, or null to leave placement untouched.
     */
    public function streamPlacementCluster(): ?string
    {
        $value = $this->options[TransportOption::STREAM_PLACEMENT_CLUSTER->value] ?? null;
        if ($value === null) {
            return null;
        }

        $cluster = trim(TypeCoercion::stringValue($value));

        return $cluster === '' ? null : $cluster;
    }

    /**
     * Returns the server tags the stream must be placed on, or null to leave placement untouched.
     *
     * Accepts the normalized list the builder stores, a raw list, or a comma-separated string.
     *
     * @return list<string>|null
     */
    public function streamPlacementTags(): ?array
    {
        $value = $this->options[TransportOption::STREAM_PLACEMENT_TAGS->value] ?? null;
        if ($value === null) {
            return null;
        }

        $tags = TypeCoercion::stringListValue($value);

        return $tags === [] ? null : $tags;
    }

    /**
     * Returns the JetStream `placement` block built from the two placement options, or null when
     * neither is set.
     *
     * Used by {@see NatsTransport::setup()}: written to the stream when non-null, otherwise the
     * stream's existing placement is left as the server reports it.
     *
     * @return array{cluster?: string, tags?: list<string>}|null
     */
    public function streamPlacement(): ?array
    {
        $placement = [];

        $cluster = $this->streamPlacementCluster();
        if ($cluster !== null) {
            $placement['cluster'] = $cluster;
        }

        $tags = $this->streamPlacementTags();
        if ($tags !== null) {
            $placement['tags'] = $tags;
        }

        return $placement === [] ? null : $placement;
    }

    /**
     * Returns the consumer max-ack-pending limit, or null to use the JetStream default.
     *
     * Caps how many delivered-but-unacknowledged messages a consumer may have outstanding; the
     * primary flow-control lever for a pull consumer.
     */
    public function maxAckPending(): ?int
    {
        return $this->nullableIntOption(TransportOption::MAX_ACK_PENDING);
    }

    /**
     * Returns the consumer inactivity threshold in milliseconds, or null to use the JetStream default.
     *
     * JetStream removes the durable consumer if it receives no pull requests for this long; the source
     * value (inactive_threshold) is in seconds.
     */
    public function inactiveThresholdMs(): ?int
    {
        $value = $this->options[TransportOption::INACTIVE_THRESHOLD->value] ?? null;

        return $value === null ? null : max(1, TypeCoercion::secondsToMs($value));
    }

    /**
     * Returns the configured consumer replay policy, or null to use the JetStream default (instant).
     */
    public function replayPolicy(): ?ReplayPolicy
    {
        $value = $this->options[TransportOption::REPLAY_POLICY->value] ?? null;

        return $value === null ? null : ReplayPolicy::from(TypeCoercion::stringValue($value));
    }

    /**
     * Returns normalized retry handler mode.
     *
     * @see RetryHandler::SYMFONY TERM the message; Symfony handles redelivery via failure transport.
     * @see RetryHandler::NATS    NAK the message; JetStream handles redelivery natively.
     */
    public function retryHandler(): RetryHandler
    {
        return $this->natsRetryHandlerEnabled ? RetryHandler::NATS : RetryHandler::SYMFONY;
    }

    /**
     * Returns true when retry handling is delegated to NATS (NAK mode).
     */
    public function isNatsRetryHandlerEnabled(): bool
    {
        return $this->natsRetryHandlerEnabled;
    }

    /**
     * Returns the NAK redelivery delay in milliseconds (0 = immediate redelivery).
     *
     * Only applies when {@see RetryHandler::NATS} is active; the source value is in seconds.
     */
    public function nakDelayMs(): int
    {
        return max(0, TypeCoercion::secondsToMs($this->options[TransportOption::NAK_DELAY->value] ?? null, 0.0));
    }

    /**
     * Returns the consumer ack-wait in milliseconds, or null to use the JetStream default.
     *
     * The source value (ack_wait) is in seconds; JetStream redelivers a message if it is not
     * acknowledged within this window.
     */
    public function ackWaitMs(): ?int
    {
        $ackWait = $this->options[TransportOption::ACK_WAIT->value] ?? null;

        return $ackWait === null ? null : max(1, TypeCoercion::secondsToMs($ackWait));
    }

    /**
     * Returns the consumer max-deliver count, or null for unlimited redeliveries.
     *
     * Caps how many times JetStream redelivers an unacknowledged message before giving up; the
     * primary guard against a poison message redelivering forever under {@see RetryHandler::NATS}.
     */
    public function maxDeliver(): ?int
    {
        return $this->nullableIntOption(TransportOption::MAX_DELIVER);
    }

    /**
     * Returns the consumer backoff schedule in milliseconds, or null when unset.
     *
     * Each entry is the delay before the corresponding redelivery attempt; the source values are in
     * seconds. Pairs with {@see maxDeliver()} under {@see RetryHandler::NATS}.
     *
     * @return list<int>|null
     */
    public function backoffMs(): ?array
    {
        $backoff = $this->options[TransportOption::BACKOFF->value] ?? null;
        if (!is_array($backoff) || $backoff === []) {
            return null;
        }

        $backoffMs = [];
        foreach ($backoff as $value) {
            $backoffMs[] = max(0, TypeCoercion::secondsToMs($value));
        }

        return $backoffMs;
    }

    /**
     * Returns true when delayed/scheduled message publishing is enabled.
     *
     * When enabled, messages with a {@see \Symfony\Component\Messenger\Stamp\DelayStamp}
     * are published as NATS scheduled messages (requires NATS 2.12+).
     */
    public function isScheduledMessagesEnabled(): bool
    {
        return $this->scheduledMessagesEnabled;
    }

    /**
     * Returns true when acknowledgements should wait for server confirmation (JetStream double-ack).
     *
     * When enabled, {@see NatsTransport::ack()} uses ackSync(), trading extra latency for a guarantee
     * that the ACK was received (a dropped ACK cannot silently lead to redelivery).
     */
    public function isAckSyncEnabled(): bool
    {
        return $this->ackSyncEnabled;
    }

    /**
     * Returns true when the transport should provision the stream/consumer on first send/get.
     *
     * When enabled, {@see NatsTransport} runs {@see NatsTransport::setup()} once, lazily, on the first
     * transport operation. Defaults to false, so by default the stream/consumer must be provisioned
     * explicitly via `messenger:setup-transports`.
     */
    public function isAutoSetupEnabled(): bool
    {
        return $this->autoSetupEnabled;
    }

    /**
     * Returns true when every published message gets an id JetStream deduplicates it by.
     *
     * When enabled, {@see NatsTransport::send()} adds a {@see DeduplicationIdStamp} to an envelope that has
     * none, and publishes the id in the Nats-Msg-Id header. Defaults to false; an id the application adds
     * itself is used either way.
     */
    public function isDeduplicationEnabled(): bool
    {
        return $this->deduplicationEnabled;
    }

    /**
     * Retrieves an integer option with fallback, using TypeCoercion for safe casting.
     */
    private function intOption(TransportOption $option, int $default): int
    {
        return TypeCoercion::intValue($this->options[$option->value] ?? null, $default);
    }

    /**
     * Retrieves a nullable integer option: null when the option is unset, otherwise the coerced int.
     *
     * Shared by the optional stream-limit and redelivery accessors so the null-passthrough policy
     * lives in one place.
     */
    private function nullableIntOption(TransportOption $option): ?int
    {
        $value = $this->options[$option->value] ?? null;

        return $value === null ? null : TypeCoercion::intValue($value);
    }

    /**
     * Retrieves a nullable boolean option: null when the option is unset, otherwise the coerced bool.
     *
     * Backs the tri-state stream policy flags (deny_delete, deny_purge, allow_direct,
     * allow_rollup_headers) so an unset flag leaves the server value untouched rather than forcing a
     * default - distinct from the always-applied boolean options (scheduled_messages, ack_sync).
     */
    private function nullableBoolOption(TransportOption $option): ?bool
    {
        $value = $this->options[$option->value] ?? null;

        return $value === null ? null : TypeCoercion::boolValue($value);
    }

    /**
     * Retrieves a string option with fallback, using TypeCoercion for safe casting.
     */
    private function stringOption(TransportOption $option, string $default): string
    {
        return TypeCoercion::stringValue($this->options[$option->value] ?? null, $default);
    }
}
