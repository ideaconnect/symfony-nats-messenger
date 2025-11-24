<?php

namespace IDCT\NatsMessenger;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Consumer\DeliverPolicy;
use Basis\Nats\Message\Ack;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Nak;
use Basis\Nats\Message\Payload;
use Basis\Nats\Queue;
use Basis\Nats\Stream\Stream;
use Exception;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NATS Messenger Transport
 *
 * This transport implements Symfony's Messenger interface to integrate with NATS JetStream,
 * a persistent, replicated message streaming platform built into NATS.
 *
 * Key features:
 * - Message publishing to NATS streams
 * - Consumer-based message retrieval with batching support
 * - Explicit acknowledgment (ack/nak) handling
 * - Message count awareness for queue management
 * - Automatic stream and consumer setup capabilities
 */
class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    /**
     * Default NATS server port (standard port for NATS).
     */
    private const DEFAULT_NATS_PORT = 4222;

    /**
     * Conversion factor from seconds to nanoseconds (1 second = 1 billion nanoseconds).
     * Used for NATS stream max age configuration.
     */
    private const SECONDS_TO_NANOSECONDS = 1_000_000_000;

    /**
     * Minimum expected path length in DSN (e.g., "/s/t" = 4 characters).
     */
    private const MIN_PATH_LENGTH = 4;

    /**
     * Default configuration options for the transport.
     * These can be overridden via DSN query parameters or constructor options.
     */
    private const DEFAULT_OPTIONS = [
        // Delay (in seconds) between fetch attempts when no messages are available
        'delay' => 0.01,
        // Consumer group name for organizing message consumption
        'consumer' => 'client',
        // Number of messages to fetch in a single batch operation
        'batching' => 1,
        // Maximum time (in seconds) to wait for a batch to fill before returning
        'max_batch_timeout' => 1,
        // Stream retention policy - max age of messages (0 = unlimited)
        'stream_max_age' => 0,
        // Stream retention policy - max bytes stored (null = unlimited)
        'stream_max_bytes' => null,
        // Stream retention policy - max number of messages (null = unlimited)
        'stream_max_messages' => null,
        // Number of stream replicas for high availability
        'stream_replicas' => 1,
    ];

    /**
     * Serializer instance used for (de)serializing messages.
     *
     * @var SerializerInterface
     */
    protected SerializerInterface $serializer;

    /**
     * Consumer instance for managing message retrieval and acknowledgment.
     * Lazy-loaded during connection initialization.
     *
     * @var Consumer|null
     */
    protected ?Consumer $consumer = null;

    /**
     * Queue instance for batch-fetching messages from the consumer.
     * Lazy-loaded during connection initialization.
     *
     * @var Queue|null
     */
    protected ?Queue $queue = null;

    /**
     * Stream instance representing the NATS JetStream.
     * Used for both publishing messages and managing consumers.
     *
     * @var Stream|null
     */
    protected ?Stream $stream = null;

    /**
     * NATS client connection.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * The topic/subject name where messages are published and consumed.
     * Format: typically "stream_name/topic_name" in the DSN.
     *
     * @var string
     */
    protected string $topic;

    /**
     * The NATS stream name that holds all messages for this transport.
     *
     * @var string
     */
    protected string $streamName;

    /**
     * Merged configuration from DSN, environment options, and defaults.
     *
     * @var array
     */
    protected array $configuration;

    /**
     * Initialize the transport with a NATS connection.
     *
     * Parses the DSN to extract connection parameters and stream/topic information,
     * then establishes the NATS client connection.
     *
     * DSN Format: nats://[user:pass@]host:port/stream_name/topic_name?param=value
     * Example: nats://nats:password@localhost:4222/my_stream/my_topic?consumer=worker&batching=5
     *
     * @param string $dsn The NATS connection DSN (marked sensitive for security reasons)
     * @param array $options Optional configuration overrides (takes precedence over DSN parameters)
     * @param SerializerInterface|null $serializer Serializer for (de)serializing messages
     */
    public function __construct(#[\SensitiveParameter] string $dsn, array $options, ?SerializerInterface $serializer = null)
    {
        $this->serializer = new IgbinarySerializer();

        $this->buildFromDsn($dsn, $options);
        $this->connect();
    }

    /**
     * Send (publish) an envelope to the NATS stream.
     *
     * This method:
     * 1. Generates a unique message ID (UUID v4)
     * 2. Attaches the ID to the envelope as a transport stamp
     * 3. Serializes the envelope using igbinary for efficient storage
     * 4. Publishes the serialized message to the stream's topic
     *
     * @param Envelope $envelope The message envelope to send
     * @return Envelope The envelope with the TransportMessageIdStamp attached
     * @throws Exception If envelope serialization fails
     */
    public function send(Envelope $envelope): Envelope
    {
        // Lazy-load stream if not already initialized
        if (!$this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
        }

        // Generate unique message identifier
        $uuid = (string) Uuid::v4();
        $envelope = $envelope->with(new TransportMessageIdStamp($uuid));

        // Serialize the envelope for storage
        try {
            $encodedMessage = $this->serializer->encode($envelope);
        } catch (\Throwable $serializationError) {
            // Check for ErrorDetailsStamp for serialization failures
            $errorStamp = $envelope->last(ErrorDetailsStamp::class);
            if ($errorStamp !== null) {
                throw new Exception($errorStamp->getExceptionMessage());
            }
            // Re-throw original serialization error if no ErrorDetailsStamp
            throw $serializationError;
        }

        if (isset($encodedMessage['headers'])) {
            $payload = new Payload($encodedMessage['body'], $encodedMessage['headers']);
        } else {
            $payload = $encodedMessage['body'];
        }

        // Publish to the NATS stream
        $this->stream->publish($this->topic, $payload);

        return $envelope;
    }

    /**
     * Retrieve messages from the consumer queue.
     *
     * This method:
     * 1. Fetches a batch of messages from the consumer queue
     * 2. Deserializes each message's payload
     * 3. Attaches the message ID (replyTo subject) as a transport stamp
     * 4. Returns the collection of envelopes ready for processing
     * 5. Negatively acknowledges any messages that fail to deserialize
     *
     * @return iterable Collection of Envelope objects ready for processing
     * @throws Exception If message deserialization fails (after sending NAK)
     */
    public function get(): iterable
    {
        // Fetch a batch of messages based on configured batch size
        $messages = $this->queue->fetchAll($this->configuration['batching']);

        $envelopes = [];

        /** @var Msg $message */
        foreach ($messages as $message) {
            // Skip empty messages
            if (empty($message->payload->body)) {
                continue;
            }

            try {
                // Deserialize the message payload back to an Envelope
                $decoded = $this->serializer->decode([
                    'body' => $message->payload->body,
                    'headers' => $message->payload->headers,
                ]);
                $envelope = $decoded->with(new TransportMessageIdStamp($message->replyTo));
                $envelopes[] = $envelope;
            } catch (\Throwable $e) {
                // Send negative acknowledgment for failed messages
                $this->sendNak($message->replyTo);
                throw $e;
            }
        }

        return $envelopes;
    }

    /**
     * Send a negative acknowledgment (NAK) to the NATS server.
     *
     * This tells NATS that the message could not be processed and should be redelivered.
     * The message will be put back into the stream for another consumer attempt.
     *
     * @param string $id The reply-to subject ID of the message to NAK
     */
    protected function sendNak($id): void
    {
        $this->client->connection->sendMessage(new Nak([
            'subject' => $id,
            'delay' => 0, // TODO: Make delay configurable
        ]));
    }

    /**
     * Acknowledge successful processing of a message.
     *
     * This tells NATS that the message has been successfully processed and should not
     * be redelivered. The message remains in the stream but is marked as acknowledged.
     *
     * @param Envelope $envelope The envelope containing the message to acknowledge
     * @throws LogicException If the envelope is missing the TransportMessageIdStamp
     */
    public function ack(Envelope $envelope): void
    {
        $id = $this->findReceivedStamp($envelope)->getId();
        $this->client->connection->sendMessage(new Ack([
            'subject' => $id
        ]));
    }

    /**
     * Reject processing of a message.
     *
     * This is equivalent to a negative acknowledgment (NAK), returning the message
     * to the stream for potential redelivery by another consumer.
     *
     * @param Envelope $envelope The envelope containing the message to reject
     */
    public function reject(Envelope $envelope): void
    {
        $id = $this->findReceivedStamp($envelope)->getId();
        $this->sendNak($id);
    }

    /**
     * Establish connection to NATS stream and consumer.
     *
     * This method initializes the stream, consumer, and queue if they haven't been
     * loaded yet. These are lazy-loaded to avoid unnecessary connections during
     * transport construction.
     *
     * Connection flow:
     * 1. Retrieve or initialize the stream
     * 2. Get or create the consumer from the stream
     * 3. Configure batching on the consumer
     * 4. Obtain the queue for batch message retrieval
     */
    protected function connect(): void
    {
        // Lazy-load stream if not already initialized
        if (!$this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
        }

        // Lazy-load consumer if not already initialized
        if (!$this->consumer) {
            $this->consumer = $this->stream->getConsumer($this->configuration['consumer']);
        }

        // Lazy-load queue if not already initialized
        if (!$this->queue) {
            $batching = $this->configuration['batching'];
            $this->consumer->setBatching($batching);
            $this->queue = $this->consumer->getQueue();
        }
    }

    /**
     * Get the count of pending messages in the consumer.
     *
     * This method attempts to retrieve the message count from the consumer first,
     * and falls back to the stream count if the consumer is not available.
     *
     * The priority is:
     * 1. Use num_ack_pending (messages delivered but not yet acknowledged)
     * 2. Fall back to num_pending (messages not yet delivered)
     * 3. If consumer unavailable, check stream message count
     * 4. If all fails, return 0
     *
     * This is used by Messenger for queue monitoring and work distribution.
     *
     * @return int The count of pending messages
     */
    public function getMessageCount(): int
    {
        try {
            // Attempt to get consumer info for accurate pending message count
            $info = $this->decodeJsonInfo($this->consumer->info());

            if ($info === null) {
                return 0;
            }

            // num_ack_pending represents messages delivered but not yet acknowledged
            // num_pending represents messages not yet delivered
            $ackPending = $info->num_ack_pending ?? 0;
            $pending = $info->num_pending ?? 0;

            // Return the maximum of the two - represents total messages requiring attention
            return max($ackPending, $pending);
        } catch (\Exception $e) {
            // If consumer doesn't exist or is unavailable, check stream message count instead
            try {
                $streamData = $this->decodeJsonInfo($this->stream->info());

                if ($streamData === null) {
                    return 0;
                }

                return $streamData->state->messages ?? 0;
            } catch (\Exception $streamException) {
                // If both consumer and stream checks fail, return 0 as fallback
                return 0;
            }
        }
    }

    /**
     * Safely decode JSON info response from NATS.
     *
     * Helper method to decode JSON responses from NATS info calls,
     * handling both successful responses and potential null/empty responses.
     *
     * @param mixed $response The response object with a body property
     * @return object|null The decoded JSON as an object, or null if unable to decode
     */
    private function decodeJsonInfo($response): ?object
    {
        if ($response === null || !isset($response->body)) {
            return null;
        }

        $decoded = json_decode($response->body);
        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /**
     * Set up the NATS stream and consumer for message handling.
     *
     * This method should be called once during initialization to prepare the transport.
     * It:
     * 1. Creates or retrieves the stream with the configured subject
     * 2. Applies retention policies (max age, max bytes, max messages)
     * 3. Sets up replicas for high availability
     * 4. Creates a consumer with explicit acknowledgment policy
     * 5. Verifies the consumer was created successfully
     *
     * Throws RuntimeException if any setup step fails.
     *
     * @throws \RuntimeException If stream/consumer setup fails
     */
    public function setup(): void
    {
        try {
            // Get or create stream from the NATS API
            $stream = $this->client->getApi()->getStream($this->streamName);

            // Retrieve the stream configuration for modification
            $streamConfig = $stream->getConfiguration();

            // Configure the stream to listen to the topic/subject
            $streamConfig->setSubjects([$this->topic]);

            // Apply retention policy: max age (convert seconds to nanoseconds)
            if (isset($this->configuration['stream_max_age']) && $this->configuration['stream_max_age'] > 0) {
                $maxAgeNanoseconds = $this->configuration['stream_max_age'] * self::SECONDS_TO_NANOSECONDS;
                $streamConfig->setMaxAge($maxAgeNanoseconds);
            }

            // Apply retention policy: max storage size in bytes
            if (isset($this->configuration['stream_max_bytes']) && $this->configuration['stream_max_bytes'] !== null) {
                $streamConfig->setMaxBytes($this->configuration['stream_max_bytes']);
            }

            // Apply replication factor for high availability
            if (isset($this->configuration['stream_replicas']) && $this->configuration['stream_replicas'] > 0) {
                $streamConfig->setReplicas($this->configuration['stream_replicas']);
            }

            // Create the stream with the configured settings
            $stream->create();
            $this->stream = $stream;

            // Create the consumer for message consumption
            $consumer = $stream->getConsumer($this->configuration['consumer']);
            $consumer->getConfiguration()->setAckPolicy(AckPolicy::EXPLICIT);
            $consumer->getConfiguration()->setDeliverPolicy(DeliverPolicy::ALL);
            $consumer->setBatching($this->configuration['batching']);
            $consumer->create();

            $this->consumer = $consumer;

            // Verify consumer was created successfully
            $consumerNames = $this->stream->getConsumerNames();
            if (!in_array($this->configuration['consumer'], $consumerNames)) {
                throw new \RuntimeException("Consumer was not created successfully.");
            }

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to setup NATS stream '{$this->streamName}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract the TransportMessageIdStamp from an envelope.
     *
     * This stamp contains the message ID (NATS replyTo subject) needed for
     * acknowledging or rejecting the message.
     *
     * @param Envelope $envelope The envelope to extract the stamp from
     * @return TransportMessageIdStamp The message ID stamp
     * @throws LogicException If the envelope is missing the required stamp
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
     * Parse DSN and initialize client configuration.
     *
     * Extracts connection parameters, authentication, stream name, and topic from the DSN.
     * Merges configuration from DSN query parameters, constructor options, and defaults.
     *
     * DSN Format: nats://[user:pass@]host:port/stream_name/topic_name?param=value
     * Example: nats://nats:password@localhost:4222/my_stream/my_topic?consumer=worker&batching=5
     *
     * Configuration precedence (highest to lowest):
     * 1. Constructor options parameter
     * 2. DSN query parameters
     * 3. Default options
     *
     * @param string $dsn The NATS connection DSN (marked sensitive for security reasons)
     * @param array $options Configuration overrides (takes precedence over DSN)
     * @throws InvalidArgumentException If DSN format is invalid or stream name is missing
     */
    protected function buildFromDsn(#[\SensitiveParameter] string $dsn, array $options = []): void
    {
        // Parse DSN components
        if (false === $components = parse_url($dsn)) {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        // Validate required components exist
        if (!isset($components['host'])) {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        // Extract connection credentials
        $connectionCredentials = [
            'host' => $components['host'],
            'port' => $components['port'] ?? self::DEFAULT_NATS_PORT,
        ];

        // Validate that path exists for stream name and topic
        if (!isset($components['path'])) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        $path = $components['path'];

        // Validate that stream name and topic are provided
        if (empty($path) || strlen($path) < self::MIN_PATH_LENGTH) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        // Parse query parameters from DSN
        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        // Merge configuration: options take precedence over query params, which take precedence over defaults
        $configuration = [];
        $configuration += $options + $query + self::DEFAULT_OPTIONS;

        // Build client connection settings
        $clientConnectionSettings = [
            'host' => $connectionCredentials['host'],
            'lang' => 'php',
            'pedantic' => false,
            'port' => $connectionCredentials['port'],
            'reconnect' => true,
            'timeout' => $configuration['max_batch_timeout'],
        ];

        // Add authentication if provided in DSN
        if (isset($components['user']) && isset($components['pass']) && !empty($components['user']) && !empty($components['pass'])) {
            $clientConnectionSettings['user'] = $components['user'];
            $clientConnectionSettings['pass'] = $components['pass'];
        }

        // Extract stream name and topic from path (format: /stream_name/topic_name)
        $pathParts = explode('/', substr($components['path'], 1));
        if (count($pathParts) < 2 || empty($pathParts[0]) || empty($pathParts[1])) {
            throw new InvalidArgumentException('NATS DSN must contain both stream name and topic name (format: /stream/topic).');
        }

        [$streamName, $topic] = $pathParts;

        // Create NATS client configuration
        $nastConfig = new Configuration($clientConnectionSettings);
        $nastConfig->setDelay(floatval($configuration['delay']));

        // Initialize and store client
        $client = new Client($nastConfig);

        $this->topic = $topic;
        $this->streamName = $streamName;
        $this->client = $client;
        $this->configuration = $configuration;
    }
}
