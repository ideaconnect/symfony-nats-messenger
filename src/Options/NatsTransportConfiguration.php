<?php

namespace IDCT\NatsMessenger\Options;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\Enum\StorageBackend;
use IDCT\NatsMessenger\TypeCoercionTrait;

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
    use TypeCoercionTrait;

    /**
     * @param string               $topic                   JetStream subject name
     * @param string               $streamName              JetStream stream name backing the subject
     * @param NatsClient           $client                  Pre-configured NATS client instance
     * @param array<string, mixed> $options                 Merged option map (method > DSN query > defaults)
     * @param bool                 $natsRetryHandlerEnabled True when retry handling is delegated to NATS (NAK mode)
     * @param bool                 $scheduledMessagesEnabled True when delayed/scheduled message publishing is enabled
     */
    public function __construct(
        public string $topic,
        public string $streamName,
        public NatsClient $client,
        private array $options,
        private bool $natsRetryHandlerEnabled,
        private bool $scheduledMessagesEnabled = false,
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
        return max(1, (int) round($this->floatOption(TransportOption::MAX_BATCH_TIMEOUT, 1.0) * 1000));
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
        $maxBytes = $this->options[TransportOption::STREAM_MAX_BYTES->value] ?? null;

        return $maxBytes === null ? null : $this->intValue($maxBytes);
    }

    /**
     * Returns stream max messages, or null for unlimited.
     *
     * When set, JetStream will discard oldest messages once the stream exceeds this count.
     */
    public function streamMaxMessages(): ?int
    {
        $maxMessages = $this->options[TransportOption::STREAM_MAX_MESSAGES->value] ?? null;

        return $maxMessages === null ? null : $this->intValue($maxMessages);
    }

    /**
     * Returns stream max messages per subject, or null for unlimited.
     *
     * When set, JetStream limits the number of retained messages for each individual subject.
     */
    public function streamMaxMessagesPerSubject(): ?int
    {
        $maxMessagesPerSubject = $this->options[TransportOption::STREAM_MAX_MESSAGES_PER_SUBJECT->value] ?? null;

        return $maxMessagesPerSubject === null ? null : $this->intValue($maxMessagesPerSubject);
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
     * Retrieves a float option with fallback, using TypeCoercionTrait for safe casting.
     */
    private function floatOption(TransportOption $option, float $default): float
    {
        return $this->floatValue($this->options[$option->value] ?? null, $default);
    }

    /**
     * Retrieves an integer option with fallback, using TypeCoercionTrait for safe casting.
     */
    private function intOption(TransportOption $option, int $default): int
    {
        return $this->intValue($this->options[$option->value] ?? null, $default);
    }

    /**
     * Retrieves a string option with fallback, using TypeCoercionTrait for safe casting.
     */
    private function stringOption(TransportOption $option, string $default): string
    {
        return $this->stringValue($this->options[$option->value] ?? null, $default);
    }
}
