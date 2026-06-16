<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\Exception\UnsupportedFeatureException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
use IDCT\NATS\JetStream\Enum\AckPolicy;
use IDCT\NATS\JetStream\Enum\DeliverPolicy;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NATS\JetStream\Schedule;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Options\RetryHandler;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use LogicException;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Symfony Messenger transport for NATS JetStream.
 *
 * Implements the full transport lifecycle: sending envelopes to a JetStream subject,
 * pulling batches via a durable pull consumer, acknowledging or rejecting messages,
 * and provisioning the underlying stream and consumer via {@see setup()}.
 *
 * Connection to NATS is established lazily on the first transport operation.
 * All JetStream interactions use explicit ACK policy with pull-based consumers.
 *
 * @see NatsTransportFactory     Creates instances of this transport from Symfony DSN configuration.
 * @see NatsTransportConfiguration  Holds the resolved, immutable runtime settings.
 */
class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface, KeepaliveReceiverInterface, CloseableTransportInterface
{
    /** Conversion factor for stream max_age (seconds → nanoseconds as required by JetStream API). */
    private const SECONDS_TO_NANOSECONDS = 1_000_000_000;

    /** Symfony serializer used to encode/decode envelopes to/from wire payloads. */
    protected SerializerInterface $serializer;

    /** Low-level NATS client managing the TCP/TLS connection and request/publish calls. */
    protected NatsClient $client;

    /** Lazily initialized JetStream context; null until {@see connectIfNeeded()} runs. */
    protected ?JetStreamContext $jetStream = null;

    /** JetStream subject name that messages are published to and consumed from. */
    protected string $topic;

    /** JetStream stream name that backs the transport subject. */
    protected string $streamName;

    /** Resolved immutable transport configuration (consumer, batching, timeouts, etc.). */
    protected NatsTransportConfiguration $configuration;

    /**
     * Creates a transport instance from DSN/options and optional serializer override.
     *
     * Parses the DSN and options via {@see NatsTransportConfigurationBuilder}, sets up
     * the serializer (defaults to {@see IgbinarySerializer} if the extension is available),
     * and stores the resolved configuration. No connection is made at construction time.
     *
     * @param string                 $dsn        NATS JetStream DSN (e.g. nats-jetstream://host:4222/stream/topic)
     * @param array<string, mixed>   $options    Transport option overrides (take precedence over DSN query params)
     * @param SerializerInterface|null $serializer Custom serializer; when null, defaults to igbinary
     */
    public function __construct(#[\SensitiveParameter] string $dsn, array $options, ?SerializerInterface $serializer = null)
    {
        if ($serializer !== null) {
            $this->serializer = $serializer;
        } elseif ($this->isExtensionLoaded('igbinary')) {
            $this->serializer = new IgbinarySerializer();
        } else {
            // PhpSerializer uses native unserialize(), which is the same untrusted-deserialization
            // (object injection) sink as igbinary. Emit a warning - not a quiet notice - so the
            // fallback is not silently relied upon in production. See the README security section.
            trigger_error(
                'The igbinary extension is not installed. Falling back to Symfony\\Component\\Messenger\\Transport\\Serialization\\PhpSerializer, which uses native unserialize() and carries the same untrusted-deserialization (object injection) risk as igbinary. Install ext-igbinary, or explicitly configure a safe serializer - especially when consuming from untrusted NATS subjects.',
                E_USER_WARNING,
            );
            $this->serializer = new PhpSerializer();
        }

        $configuration = (new NatsTransportConfigurationBuilder())->build($dsn, $options);
        $this->configuration = $configuration;
        $this->topic = $configuration->topic;
        $this->streamName = $configuration->streamName;
        $this->client = $configuration->client;
    }

    /**
     * Exposed for testability around extension checks.
     */
    protected function isExtensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /**
     * Sends a messenger envelope to JetStream.
     *
     * Assigns a UUID v4 transport message ID, serializes the envelope, and publishes
     * the payload (with any envelope headers) to the configured subject via
     * {@see JetStreamContext::publish()}, which validates the JetStream publish
     * acknowledgement and fails closed on an error or malformed response.
     *
     * When scheduled messages are enabled and the envelope carries a {@see DelayStamp},
     * the message is published to a unique delayed subject with NATS schedule headers,
     * causing JetStream to hold it until the scheduled time before delivering it to
     * the original topic.
     *
     * @throws RuntimeException     If serialization fails and the envelope carries an ErrorDetailsStamp.
     * @throws \Throwable           The original serializer exception when serialization fails and the
     *                              envelope carries no ErrorDetailsStamp (re-thrown unchanged).
     * @throws JetStreamException   If JetStream rejects the publish or returns an invalid ack.
     */
    public function send(Envelope $envelope): Envelope
    {
        $uuid = (string) Uuid::v4();
        $envelope = $envelope->with(new TransportMessageIdStamp($uuid));

        try {
            $encodedMessage = $this->serializer->encode($envelope);
        } catch (\Throwable $serializationError) {
            $errorStamp = $envelope->last(ErrorDetailsStamp::class);
            if ($errorStamp !== null) {
                throw new RuntimeException($errorStamp->getExceptionMessage(), 0, $serializationError);
            }

            throw $serializationError;
        }

        $payload = TypeCoercion::stringValue($encodedMessage['body']);
        $headers = is_array($encodedMessage['headers'] ?? null) ? $encodedMessage['headers'] : [];
        $topic = $this->topic;

        $delayMs = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        if ($delayMs > 0 && $this->configuration->isScheduledMessagesEnabled()) {
            $deliverAt = new \DateTimeImmutable('+' . $delayMs . ' milliseconds');
            $headers['Nats-Schedule'] = Schedule::at($deliverAt);
            $headers['Nats-Schedule-Target'] = $this->topic;
            $topic = $this->topic . '.delayed.' . $uuid;
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[(string) $name] = TypeCoercion::stringValue($value);
        }

        // A single JetStream publish path for both plain and header-carrying (incl. scheduled)
        // messages. JetStreamContext::publish() retries transient 503 "no responders" and parses
        // the PubAck, throwing JetStreamException on an empty/malformed reply or a reported error -
        // so the publish fails closed instead of silently accepting an invalid acknowledgement.
        $this->jetStream()->publish($topic, $payload, $normalizedHeaders)->await();

        return $envelope;
    }

    /**
     * Pulls and decodes a batch of envelopes from JetStream.
     *
     * Fetches up to {@see NatsTransportConfiguration::batching()} messages with the
     * configured timeout. JetStream status 404 (consumer not found) and 408 (timeout / no messages)
     * are treated as empty results. A message without a reply (ack) subject is skipped
     * (it can be neither acknowledged nor rejected); a message with an empty payload is
     * TERMed so JetStream stops redelivering it, since it can never decode into an
     * envelope. On deserialization failure the message is rejected via
     * {@see handleFailedDelivery()} before the exception propagates.
     *
     * @return iterable<Envelope>
     */
    public function get(): iterable
    {
        try {
            $messages = $this->jetStream()->fetchBatch(
                $this->streamName,
                $this->configuration->consumer(),
                $this->configuration->batching(),
                $this->configuration->maxBatchTimeoutMs()
            )->await();
        } catch (JetStreamException $e) {
            if ($e->getCode() === 404 || $e->getCode() === 408) {
                return [];
            }

            throw $e;
        }

        foreach ($messages as $message) {
            // A delivered message without a reply (ack) subject can neither be acknowledged
            // nor rejected, so an envelope built from it could never be completed by the
            // worker. Skip it rather than yield an envelope with an unusable transport id.
            // (Pull-delivered JetStream data messages always carry an ack subject.)
            $replyTo = $message->replyTo;
            if ($replyTo === null || $replyTo === '') {
                continue;
            }

            // An empty payload can never decode into a Messenger envelope. Skipping it without
            // acknowledging would leave it unacked, so JetStream would redeliver it every ack_wait
            // forever (a poison loop). TERM it instead - redelivery cannot fix an empty body - so
            // it is dropped regardless of the configured retry handler.
            if ($message->payload === '') {
                $this->sendTerm($replyTo);

                continue;
            }

            $headers = ($message->rawHeaders !== null && $message->rawHeaders !== '')
                ? NatsHeaders::fromWireBlock($message->rawHeaders)
                : [];

            try {
                $decoded = $this->serializer->decode([
                    'body' => $message->payload,
                    'headers' => $headers,
                ]);
            } catch (\Throwable $e) {
                $this->handleFailedDelivery($replyTo);

                throw $e;
            }

            yield $decoded->with(new TransportMessageIdStamp($replyTo));
        }
    }

    /**
     * Sends a NAK to request redelivery in JetStream.
     *
     * When a positive nak_delay is configured, the NAK carries that delay so JetStream waits before
     * redelivering (backoff) instead of redelivering immediately.
     *
     * @param string $id The replyTo address (JetStream delivery token)
     */
    protected function sendNak(string $id): void
    {
        $message = $this->buildAckMessage($id);
        $nakDelayMs = $this->configuration->nakDelayMs();

        if ($nakDelayMs > 0) {
            $this->jetStream()->nakWithDelay($message, $nakDelayMs)->await();

            return;
        }

        $this->jetStream()->nak($message)->await();
    }

    /**
     * Sends TERM to stop JetStream redelivery for a failed delivery.
     *
     * Used when retry handling is delegated to Symfony (the default): the message
     * is terminated in JetStream so it won't be redelivered by NATS.
     *
     * @param string $id The replyTo address (JetStream delivery token)
     */
    protected function sendTerm(string $id): void
    {
        $this->jetStream()->term($this->buildAckMessage($id))->await();
    }

    /**
     * Acknowledges successful handling of a received envelope.
     *
     * Extracts the JetStream delivery token from the envelope's TransportMessageIdStamp
     * and sends an ACK to JetStream so the message won't be redelivered. When the
     * {@see NatsTransportConfiguration::isAckSyncEnabled()} option is on, the ACK waits for
     * server confirmation (double-ack) so a dropped ACK cannot silently cause redelivery.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function ack(Envelope $envelope): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());
        $message = $this->buildAckMessage($id);

        if ($this->configuration->isAckSyncEnabled()) {
            $this->jetStream()->ackSync($message)->await();

            return;
        }

        $this->jetStream()->ack($message)->await();
    }

    /**
     * Rejects a received envelope according to configured retry strategy.
     *
     * Delegates to {@see handleFailedDelivery()}: sends TERM when using Symfony retry
     * handling or NAK when using NATS-native redelivery.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function reject(Envelope $envelope): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());
        $this->handleFailedDelivery($id);
    }

    /**
     * Signals to JetStream that a received message is still being processed.
     *
     * Sends an in-progress (+WPI) acknowledgement so the server resets the redelivery timer,
     * preventing a long-running handler from losing its message to ack_wait expiry before it
     * finishes. NATS resets the timer to the consumer's configured ack_wait, so the $seconds
     * hint from Symfony is advisory and not forwarded.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());
        $this->jetStream()->inProgress($this->buildAckMessage($id))->await();
    }

    /**
     * Closes the NATS connection and releases the transport's resources.
     *
     * No-op when no connection was ever opened (the connection is lazy). After closing, the next
     * transport operation reconnects lazily via {@see jetStream()}, so the transport stays reusable.
     */
    public function close(): void
    {
        if ($this->jetStream === null) {
            return;
        }

        $this->client->disconnect()->await();
        $this->jetStream = null;
    }

    /**
     * Opens NATS connection and initializes JetStream context.
     */
    protected function connect(): void
    {
        $this->client->connect()->await();
        $this->jetStream = $this->client->jetStream();
    }

    /**
     * Returns an approximate count of messages still to be processed.
     *
     * Tries the consumer info first and returns num_ack_pending + num_pending: those two
     * counts describe disjoint sets (delivered-but-unacked vs. not-yet-delivered), so the
     * total outstanding work is their sum. Falls back to the stream-level message count,
     * then to 0 if both queries fail.
     */
    public function getMessageCount(): int
    {
        try {
            $consumerInfo = $this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer())->await();
            $ackPending = TypeCoercion::intValue($consumerInfo->raw['num_ack_pending'] ?? 0);
            $pending = TypeCoercion::intValue($consumerInfo->raw['num_pending'] ?? 0);

            return $ackPending + $pending;
        } catch (\Throwable) {
            try {
                $streamInfo = $this->jetStream()->getStream($this->streamName)->await();
                $state = is_array($streamInfo->raw['state'] ?? null) ? $streamInfo->raw['state'] : [];

                return TypeCoercion::intValue($state['messages'] ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        }
    }

    /**
     * Ensures stream and consumer are present and configured.
     *
     * Creates the JetStream stream with the configured retention limits (max age,
     * bytes, messages, replicas). If the stream already exists, it is updated with
     * the new settings. Then creates a durable pull consumer with explicit ACK
     * policy and validates it matches expectations.
     *
     * @throws RuntimeException If stream or consumer setup fails
     */
    public function setup(): void
    {
        try {
            $subjects = $this->buildDesiredSubjects();
            $streamConfiguration = $this->buildManagedStreamConfiguration($subjects);

            try {
                $this->jetStream()->addStream($streamConfiguration)->await();
            } catch (UnsupportedFeatureException $unsupportedFeature) {
                // A version-gated feature (e.g. allow_msg_schedules) was rejected by an older server.
                // That is not a pre-existing-stream conflict, so skip the existence check and surface
                // an actionable message via the outer handler.
                throw $unsupportedFeature;
            } catch (JetStreamException $streamCreateException) {
                // Don't string-match the server's error text to detect a pre-existing stream - the
                // message wording varies across NATS versions. Ask JetStream directly: if the stream
                // now exists, update it (reusing the fetched config); if it does not (404), the create
                // failure was a genuine error, so rethrow it.
                $existingStream = $this->getExistingStream();
                if ($existingStream === null) {
                    throw $streamCreateException;
                }

                // The update API takes a raw config array merged with the live server config, so derive
                // the managed fields from the same StreamConfiguration (dropping name/subjects, which
                // the update path handles itself).
                $managedOptions = $streamConfiguration->toArray();
                unset($managedOptions['name'], $managedOptions['subjects']);

                $updatedConfiguration = $this->buildUpdatedStreamConfiguration($existingStream, $managedOptions, $subjects);
                $this->jetStream()->updateStream($this->streamName, $updatedConfiguration)->await();
            }

            $consumerConfiguration = ConsumerConfiguration::create()
                ->durable($this->configuration->consumer())
                ->filterSubject($this->topic)
                ->ackPolicy(AckPolicy::Explicit)
                ->deliverPolicy(DeliverPolicy::All);

            // Optional NATS-native redelivery tuning (primarily relevant with retry_handler=nats).
            $ackWaitMs = $this->configuration->ackWaitMs();
            if ($ackWaitMs !== null) {
                $consumerConfiguration->ackWait($ackWaitMs);
            }

            $maxDeliver = $this->configuration->maxDeliver();
            if ($maxDeliver !== null) {
                $consumerConfiguration->maxDeliver($maxDeliver);
            }

            $backoffMs = $this->configuration->backoffMs();
            if ($backoffMs !== null) {
                $consumerConfiguration->backoff($backoffMs);
            }

            $consumerInfo = $this->jetStream()->addConsumer($this->streamName, $consumerConfiguration)->await();
            $this->assertConsumerMatchesConfiguration($consumerInfo);
        } catch (UnsupportedFeatureException $unsupportedFeature) {
            throw new RuntimeException($this->describeUnsupportedFeature($unsupportedFeature), 0, $unsupportedFeature);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to setup NATS stream '{$this->streamName}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts transport message ID stamp from an envelope.
     */
    private function findReceivedStamp(Envelope $envelope): TransportMessageIdStamp
    {
        /** @var TransportMessageIdStamp|null $receivedStamp */
        $receivedStamp = $envelope->last(TransportMessageIdStamp::class);

        if (null === $receivedStamp) {
            throw new LogicException('No TransportMessageIdStamp found on the Envelope.');
        }

        return $receivedStamp;
    }

    /**
     * Lazily connects to NATS only when transport operations require it.
     *
     * Called internally by {@see jetStream()} to ensure the connection is
     * established before any JetStream API call.
     */
    private function connectIfNeeded(): void
    {
        if ($this->jetStream === null) {
            $this->connect();
        }
    }

    /**
     * Returns the JetStream context, connecting lazily if needed.
     *
     * @throws LogicException If connection succeeds but JetStream context is still null
     */
    private function jetStream(): JetStreamContext
    {
        $this->connectIfNeeded();

        if ($this->jetStream === null) {
            throw new LogicException('JetStream context is not available.');
        }

        return $this->jetStream;
    }

    /**
     * Applies configured failure strategy for failed deliveries.
     *
     * When {@see RetryHandler::NATS} is active, sends a NAK so JetStream redelivers
     * the message. Otherwise sends TERM so JetStream stops redelivery, allowing
     * Symfony's retry/failure transport to handle the failure.
     *
     * @param string $id The JetStream replyTo delivery token
     */
    private function handleFailedDelivery(string $id): void
    {
        if ($this->configuration->retryHandler() === RetryHandler::NATS) {
            $this->sendNak($id);

            return;
        }

        $this->sendTerm($id);
    }

    /**
     * Builds a minimal message wrapper used by JetStream ack/nak/term APIs.
     */
    private function buildAckMessage(string $replyTo): NatsMessage
    {
        return new NatsMessage(
            subject: $this->topic,
            sid: 0,
            replyTo: $replyTo,
            payload: ''
        );
    }

    /**
     * Verifies that the configured durable consumer was created with the expected pull settings.
     */
    private function assertConsumerMatchesConfiguration(ConsumerInfo $consumerInfo): void
    {
        if ($consumerInfo->streamName !== $this->streamName || $consumerInfo->name !== $this->configuration->consumer()) {
            throw new RuntimeException('Consumer was not created successfully.');
        }

        /** @var array<string, mixed> $config */
        $config = is_array($consumerInfo->raw['config'] ?? null) ? $consumerInfo->raw['config'] : [];

        if (($config['ack_policy'] ?? null) !== 'explicit') {
            throw new RuntimeException('Consumer ack policy must be explicit.');
        }

        if (($config['deliver_policy'] ?? null) !== 'all') {
            throw new RuntimeException('Consumer deliver policy must be all.');
        }

        if (($config['filter_subject'] ?? null) !== $this->topic) {
            throw new RuntimeException('Consumer filter subject does not match the configured topic.');
        }

        if ($consumerInfo->push) {
            throw new RuntimeException('Consumer must be configured as a pull consumer.');
        }
    }

    /**
     * Returns the existing stream, or null when it does not exist.
     *
     * Detects existence deterministically via a JetStream stream-info lookup (a 404 means the stream
     * is absent), instead of matching server-specific "already in use" / "already exists" error
     * strings, whose wording varies across NATS versions. Any non-404 error propagates.
     */
    private function getExistingStream(): ?StreamInfo
    {
        try {
            return $this->jetStream()->getStream($this->streamName)->await();
        } catch (JetStreamException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Builds an actionable message for a version-gated feature the connected server is too old for.
     *
     * The only such feature this transport enables is `allow_msg_schedules` (via `scheduled_messages`),
     * so that case gets a tailored hint; anything else falls back to a generic message.
     */
    private function describeUnsupportedFeature(UnsupportedFeatureException $exception): string
    {
        $serverVersion = $exception->serverVersion ?? 'an older version';

        if ($this->configuration->isScheduledMessagesEnabled() && $exception->feature === 'allow_msg_schedules') {
            return sprintf(
                "The 'scheduled_messages' option requires NATS Server >= %s, but the connected server reports %s. Disable scheduled_messages or upgrade NATS.",
                $exception->requiredVersion,
                $serverVersion,
            );
        }

        return sprintf(
            "NATS Server feature '%s' requires version >= %s, but the connected server reports %s.",
            $exception->feature,
            $exception->requiredVersion,
            $serverVersion,
        );
    }

    /**
     * Builds the typed stream configuration managed by this transport.
     *
     * Used directly to create the stream (via {@see JetStreamContext::addStream()}) and, on the update
     * path, via {@see StreamConfiguration::toArray()} so the managed fields have a single definition.
     * {@see StreamConfiguration::maxAge()} performs the seconds→nanoseconds conversion internally.
     *
     * @param list<string> $subjects
     */
    private function buildManagedStreamConfiguration(array $subjects): StreamConfiguration
    {
        $streamConfiguration = (new StreamConfiguration($this->streamName))
            ->subjects(...$subjects)
            ->storage($this->configuration->streamStorage());

        if ($this->configuration->streamMaxAgeSeconds() > 0) {
            $streamConfiguration->maxAge($this->configuration->streamMaxAgeSeconds());
        }

        if ($this->configuration->streamMaxBytes() !== null) {
            $streamConfiguration->maxBytes($this->configuration->streamMaxBytes());
        }

        if ($this->configuration->streamMaxMessages() !== null) {
            $streamConfiguration->maxMessages($this->configuration->streamMaxMessages());
        }

        if ($this->configuration->streamMaxMessagesPerSubject() !== null) {
            $streamConfiguration->maxMsgsPerSubject($this->configuration->streamMaxMessagesPerSubject());
        }

        if ($this->configuration->streamReplicas() > 0) {
            $streamConfiguration->replicas($this->configuration->streamReplicas());
        }

        if ($this->configuration->isScheduledMessagesEnabled()) {
            $streamConfiguration->set('allow_msg_schedules', true);
        }

        return $streamConfiguration;
    }

    /**
     * Returns the subjects this transport needs to be present on the stream.
     *
     * @return list<string>
     */
    private function buildDesiredSubjects(): array
    {
        $subjects = [$this->topic];
        if ($this->configuration->isScheduledMessagesEnabled()) {
            $subjects[] = $this->topic . '.delayed.>';
        }

        return $subjects;
    }

    /**
     * Builds the update payload for an existing stream while preserving server-side fields.
     *
     * @param array<string, mixed> $managedOptions
     * @param list<string>         $desiredSubjects
     * @return array<string, mixed>
     */
    private function buildUpdatedStreamConfiguration(
        StreamInfo $streamInfo,
        array $managedOptions,
        array $desiredSubjects,
    ): array {
        /** @var array<string, mixed> $serverConfiguration */
        $serverConfiguration = is_array($streamInfo->raw['config'] ?? null) ? $streamInfo->raw['config'] : [];
        unset($serverConfiguration['name']);
        $serverConfiguration = $this->normalizeStreamConfigurationForUpdate($serverConfiguration);

        $serverSubjects = $this->normalizeSubjects($serverConfiguration['subjects'] ?? $streamInfo->subjects);
        $mergedSubjects = $this->mergeSubjects($serverSubjects, $desiredSubjects);

        $updatedConfiguration = array_merge($serverConfiguration, $managedOptions, [
            'subjects' => $mergedSubjects,
        ]);

        // The transport authoritatively manages these retention limits. On update we always write
        // the value - including JetStream's "unlimited" sentinels (max_age 0, others -1) when the
        // option is unset - so a previously-configured limit is actually relaxed/cleared instead of
        // being preserved from the existing server configuration by the array_merge above.
        $updatedConfiguration['max_age'] = $this->configuration->streamMaxAgeSeconds() > 0
            ? $this->configuration->streamMaxAgeSeconds() * self::SECONDS_TO_NANOSECONDS
            : 0;
        $updatedConfiguration['max_bytes'] = $this->configuration->streamMaxBytes() ?? -1;
        $updatedConfiguration['max_msgs'] = $this->configuration->streamMaxMessages() ?? -1;
        $updatedConfiguration['max_msgs_per_subject'] = $this->configuration->streamMaxMessagesPerSubject() ?? -1;

        if (array_key_exists('storage', $serverConfiguration)) {
            $updatedConfiguration['storage'] = $serverConfiguration['storage'];
        }

        // Preserve the existing replica count unless stream_replicas was explicitly configured.
        // The managed default (num_replicas = 1) would otherwise overwrite the server value via the
        // array_merge above and silently downscale a stream created with more replicas (e.g. in a
        // cluster), eliminating its high-availability/durability with no warning.
        if (!$this->configuration->hasExplicitStreamReplicas() && array_key_exists('num_replicas', $serverConfiguration)) {
            $updatedConfiguration['num_replicas'] = $serverConfiguration['num_replicas'];
        }

        // Authoritatively manage allow_msg_schedules: write true when scheduled_messages is enabled,
        // and explicitly false when it is disabled on a stream that previously had the flag set, so
        // turning the option off actually clears it instead of the array_merge preserving the server's
        // true. When the flag is off and the server never had it, leave it absent so the field is not
        // sent to a server too old to understand it (NATS < 2.12).
        if ($this->configuration->isScheduledMessagesEnabled()) {
            $updatedConfiguration['allow_msg_schedules'] = true;
        } elseif (array_key_exists('allow_msg_schedules', $serverConfiguration)) {
            $updatedConfiguration['allow_msg_schedules'] = false;
        }

        /** @var array<string, mixed> $updatedConfiguration */
        return $updatedConfiguration;
    }

    /**
     * Normalizes stream config fields returned by getStream() before sending them back to updateStream().
     *
     * JetStream returns some map-like fields as empty arrays when unset. Those must be
     * re-encoded as JSON objects on update or the server rejects the payload.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function normalizeStreamConfigurationForUpdate(array $configuration): array
    {
        foreach (['consumer_limits', 'metadata'] as $objectLikeKey) {
            if (array_key_exists($objectLikeKey, $configuration) && $configuration[$objectLikeKey] === []) {
                $configuration[$objectLikeKey] = (object) [];
            }
        }

        return $configuration;
    }

    /**
     * @param mixed $subjects
     * @return list<string>
     */
    private function normalizeSubjects(mixed $subjects): array
    {
        if (!is_array($subjects)) {
            return [];
        }

        return array_values(array_filter($subjects, static fn (mixed $subject): bool => is_string($subject) && $subject !== ''));
    }

    /**
     * @param list<string> $existingSubjects
     * @param list<string> $desiredSubjects
     * @return list<string>
     */
    private function mergeSubjects(array $existingSubjects, array $desiredSubjects): array
    {
        $mergedSubjects = $existingSubjects;

        foreach ($desiredSubjects as $subject) {
            if (!in_array($subject, $mergedSubjects, true)) {
                $mergedSubjects[] = $subject;
            }
        }

        return $mergedSubjects;
    }
}
