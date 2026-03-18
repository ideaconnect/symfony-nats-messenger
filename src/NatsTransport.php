<?php

namespace IDCT\NatsMessenger;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Options\RetryHandler;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use LogicException;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
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
class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    use TypeCoercionTrait;

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
        if ($serializer === null && !$this->isExtensionLoaded('igbinary')) {
            trigger_error(
                'The igbinary extension is not installed. Please install ext-igbinary for optimal performance with the default serializer, or provide a custom serializer.',
            );
        }

        $this->serializer = $serializer ?? new IgbinarySerializer();

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
     * the payload to the configured subject. When the serialized envelope includes
     * headers, uses request-with-headers and validates the JetStream publish ack.
     *
     * @throws RuntimeException If serialization fails due to an existing ErrorDetailsStamp,
     *                          or if JetStream returns a publish error.
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

        $payload = $this->stringValue($encodedMessage['body']);
        $headers = is_array($encodedMessage['headers'] ?? null) ? $encodedMessage['headers'] : [];
        $jetStream = $this->jetStream();

        if ($headers === []) {
            $jetStream->publish($this->topic, $payload)->await();
        } else {
            $normalizedHeaders = [];
            foreach ($headers as $name => $value) {
                $normalizedHeaders[(string) $name] = $this->stringValue($value);
            }

            $reply = $this->client->requestWithHeaders($this->topic, $payload, $normalizedHeaders)->await();
            $this->assertJetStreamPublishSucceeded($this->stringValue($reply->payload));
        }

        return $envelope;
    }

    /**
     * Pulls and decodes a batch of envelopes from JetStream.
     *
     * Fetches up to {@see NatsTransportConfiguration::batching()} messages with the
     * configured timeout. HTTP 404 (consumer not found) and 408 (timeout / no messages)
     * are treated as empty results. On deserialization failure the message is rejected
     * via {@see handleFailedDelivery()} before the exception propagates.
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

        $envelopes = [];

        foreach ($messages as $message) {
            if ($message->payload === '') {
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

                $envelopes[] = $decoded->with(new TransportMessageIdStamp((string) $message->replyTo));
            } catch (\Throwable $e) {
                if ($message->replyTo !== null && $message->replyTo !== '') {
                    $this->handleFailedDelivery($message->replyTo);
                }

                throw $e;
            }
        }

        return $envelopes;
    }

    /**
     * Sends a NAK to request redelivery in JetStream.
     *
     * @param string $id The replyTo address (JetStream delivery token)
     */
    protected function sendNak(string $id): void
    {
        $this->jetStream()->nak($this->buildAckMessage($id))->await();
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
     * and sends an ACK to JetStream so the message won't be redelivered.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function ack(Envelope $envelope): void
    {
        $id = $this->stringValue($this->findReceivedStamp($envelope)->getId());
        $this->jetStream()->ack($this->buildAckMessage($id))->await();
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
        $id = $this->stringValue($this->findReceivedStamp($envelope)->getId());
        $this->handleFailedDelivery($id);
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
     * Returns an approximate message count from consumer or stream state.
     *
     * Tries the consumer info first (num_ack_pending / num_pending), then falls
     * back to stream-level message count. Returns 0 if both queries fail.
     */
    public function getMessageCount(): int
    {
        try {
            $consumerInfo = $this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer())->await();
            $ackPending = $this->intValue($consumerInfo->raw['num_ack_pending'] ?? 0);
            $pending = $this->intValue($consumerInfo->raw['num_pending'] ?? 0);

            return max($ackPending, $pending);
        } catch (\Exception $e) {
            try {
                $streamInfo = $this->jetStream()->getStream($this->streamName)->await();
                $state = is_array($streamInfo->raw['state'] ?? null) ? $streamInfo->raw['state'] : [];

                return $this->intValue($state['messages'] ?? 0);
            } catch (\Exception $streamException) {
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
            $streamOptions = [];

            if ($this->configuration->streamMaxAgeSeconds() > 0) {
                $streamOptions['max_age'] = $this->configuration->streamMaxAgeSeconds() * self::SECONDS_TO_NANOSECONDS;
            }

            if ($this->configuration->streamMaxBytes() !== null) {
                $streamOptions['max_bytes'] = $this->configuration->streamMaxBytes();
            }

            if ($this->configuration->streamMaxMessages() !== null) {
                $streamOptions['max_msgs'] = $this->configuration->streamMaxMessages();
            }

            if ($this->configuration->streamReplicas() > 0) {
                $streamOptions['num_replicas'] = $this->configuration->streamReplicas();
            }

            try {
                $this->jetStream()->createStream($this->streamName, [$this->topic], $streamOptions)->await();
            } catch (JetStreamException $streamCreateException) {
                if (!$this->isStreamAlreadyExistsException($streamCreateException)) {
                    throw $streamCreateException;
                }

                $this->jetStream()->updateStream($this->streamName, array_merge($streamOptions, [
                    'subjects' => [$this->topic],
                ]))->await();
            }

            $consumerInfo = $this->jetStream()->createConsumer(
                $this->streamName,
                $this->configuration->consumer(),
                $this->topic,
                [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                ]
            )->await();
            $this->assertConsumerMatchesConfiguration($consumerInfo);
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
            throw new LogicException('No ReceivedStamp found on the Envelope.');
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
     * Validates JetStream publish response payload and throws on protocol-level errors.
     *
     * Parses the JSON response from a JetStream publish-with-headers request.
     * If the response contains an "error" key, extracts the description and code
     * to throw a meaningful RuntimeException.
     *
     * @param string $payload Raw JSON response body from JetStream
     *
     * @throws RuntimeException If the response contains a JetStream error
     */
    private function assertJetStreamPublishSucceeded(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected JetStream publish response.');
        }

        if (!array_key_exists('error', $decoded)) {
            return;
        }

        $error = $decoded['error'];
        if (is_array($error)) {
            $description = $this->stringValue($error['description'] ?? 'JetStream publish error', 'JetStream publish error');
            $code = $this->intValue($error['code'] ?? 0);

            throw new RuntimeException($description, $code);
        }

        if (is_string($error) && $error !== '') {
            throw new RuntimeException($error, $this->intValue($decoded['code'] ?? 0));
        }

        throw new RuntimeException('JetStream publish error');
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
     * Determines whether a createStream failure indicates an existing stream conflict.
     *
     * Checks both the HTTP-style status code (400) and common error message patterns
     * returned by different NATS server versions.
     *
     * @param JetStreamException $exception The exception from a failed createStream call
     */
    private function isStreamAlreadyExistsException(JetStreamException $exception): bool
    {
        if ($exception->getCode() === 400) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'already in use')
            || str_contains($message, 'stream name already in use')
            || str_contains($message, 'already exists');
    }
}
