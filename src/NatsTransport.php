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
use Basis\Nats\Queue;
use Basis\Nats\Stream\Stream;
use Exception;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    private const DEFAULT_OPTIONS = [
        'delay' => 0.01, // delay between fetch attempts, retry, TODO: other options than fixed
        'consumer' => 'client', // consumer name
        'batching' => 1, // number of messages to fetch in one batch
        'max_batch_timeout' => 0.5, // seconds, allow to define how long to wait for batch to fill
        'stream_max_age' => 0, // in seconds, 0 means unlimited
        'stream_max_bytes' => null, // in bytes, null means unlimited
        'stream_max_messages' => null, // in messages, null means unlimited
        'stream_replicas' => 1, // number of replicas for the stream
    ];

    /**
     * @var Consumer|null
     */
    protected ?Consumer $consumer = null;

    /**
     * @var Queue|null
     */
    protected ?Queue $queue = null;
    protected ?Stream $stream = null;
    protected Client $client;
    protected string $topic;
    protected string $streamName;
    protected array $configuration;

    public function __construct(#[\SensitiveParameter] string $dsn, ?array $options = [])
    {
        $this->buildFromDsn($dsn, $options);
        $this->connect();
    }

    public function send(Envelope $envelope): Envelope
    {
        if (!$this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
        }

        $uuid = (string) Uuid::v4();
        $envelope = $envelope->with(new TransportMessageIdStamp($uuid));
        try {
            $encodedMessage = igbinary_serialize($envelope);
        } catch (Exception $e) {
            $realError = $envelope->last(ErrorDetailsStamp::class);
            throw new Exception($realError->getExceptionMessage());
        }

        $this->stream->put($this->topic, $encodedMessage);
        return $envelope;
    }

    public function get(): iterable
    {
        $messages = $this->queue->fetchAll($this->configuration['batching']);

        $envelopes = [];

        /** @var Msg */
        foreach ($messages as $message) {
            if (empty($message->payload->body)) {
                continue;
            }

            try {
                $decoded = igbinary_unserialize($message->payload->body);
                $envelope = $decoded->with(new TransportMessageIdStamp($message->replyTo));
                $envelopes[] = $envelope;
            } catch (Exception $e) {
                $this->sendNak($message->replyTo);
                throw $e;
            }
        }

        return $envelopes;
    }

    protected function sendNak($id)
    {
        $this->client->connection->sendMessage(new Nak([
            'subject' => $id,
            'delay' => 0, //TODO
        ]));
    }

    public function ack(Envelope $envelope): void
    {
        $id = $this->findReceivedStamp($envelope)->getId();
        $this->client->connection->sendMessage(new Ack([
            'subject' => $id
        ]));
    }

    public function reject(Envelope $envelope): void
    {
        $id = $this->findReceivedStamp($envelope)->getId();
        $this->sendNak($id);
    }

    protected function connect()
    {
        if (!$this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
        }

        if (!$this->consumer) {
            $this->consumer = $this->stream->getConsumer($this->configuration['consumer']);
        }

        if (!$this->queue) {
            $batching = $this->configuration['batching'];
            $this->consumer->setBatching($batching);
            $this->queue = $this->consumer->getQueue();
        }
    }

    public function getMessageCount(): int
    {


        try {
            // First try to get consumer info
            $info = json_decode($this->consumer->info()->body);

            // Use num_ack_pending which represents messages delivered but not yet acknowledged
            // If num_ack_pending is 0 but num_pending > 0, use num_pending (messages not yet delivered)
            $ackPending = $info->num_ack_pending ?? 0;
            $pending = $info->num_pending ?? 0;

            return max($ackPending, $pending);
        } catch (\Exception $e) {
            // If consumer doesn't exist, check stream message count instead
            try {
                $streamInfo = $this->stream->info();
                $streamData = json_decode($streamInfo->body);
                return $streamData->state->messages ?? 0;
            } catch (\Exception $streamException) {
                // If both consumer and stream checks fail, return 0
                return 0;
            }
        }
    }

    public function setup(): void
    {
        try {
            // Get stream from the API
            $stream = $this->client->getApi()->getStream($this->streamName);

            // Use createIfNotExists to avoid errors if stream already exists
            $streamConfig = $stream->getConfiguration();

            // Configure the stream with the topic as subject
            $streamConfig->setSubjects([$this->topic]);

            // Apply additional configuration from options
            if (isset($this->configuration['stream_max_age']) && $this->configuration['stream_max_age'] > 0) {
                // Convert seconds to nanoseconds for NATS
                $maxAgeNanoseconds = $this->configuration['stream_max_age'] * 1_000_000_000;
                $streamConfig->setMaxAge($maxAgeNanoseconds);
            }

            if (isset($this->configuration['stream_max_bytes']) && $this->configuration['stream_max_bytes'] !== null) {
                $streamConfig->setMaxBytes($this->configuration['stream_max_bytes']);
            }

            if (isset($this->configuration['stream_replicas']) && $this->configuration['stream_replicas'] > 0) {
                $streamConfig->setReplicas($this->configuration['stream_replicas']);
            }

            // Create the stream if it doesn't exist
            $stream->create();

            $this->stream = $stream;

            // Also create the consumer to ensure the transport is fully ready
            $consumer = $stream->getConsumer($this->configuration['consumer']);
            $consumer->getConfiguration()->setAckPolicy(AckPolicy::EXPLICIT);
            $consumer->getConfiguration()->setDeliverPolicy(DeliverPolicy::ALL);
            $consumer->setBatching($this->configuration['batching']);
            $consumer->create();

            $this->consumer = $consumer;

            $cons = $this->stream->getConsumerNames();
            if (!in_array($this->configuration['consumer'], $cons)) {
                throw new \RuntimeException("Consumer was not created successfully.");
            }

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to setup NATS stream '{$this->streamName}': " . $e->getMessage(), 0, $e);
        }
    }

    private function findReceivedStamp(Envelope $envelope): TransportMessageIdStamp
    {
        /** @var RedisReceivedStamp|null $redisReceivedStamp */
        $receivedStamp = $envelope->last(TransportMessageIdStamp::class);

        if (null === $receivedStamp) {
            throw new LogicException('No ReceivedStamp found on the Envelope.');
        }

        return $receivedStamp;
    }

    protected function buildFromDsn(#[\SensitiveParameter] string $dsn, array $options = []): void
    {
        if (false === $components = parse_url($dsn)) {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        $connectionCredentials = [
            'host' => $components['host'],
            'port' => $components['port'] ?? 4222,
        ];

        $path = $components['path'];

        if (empty($path) || strlen($path) < 4) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        $configuration = [];
        $configuration += $options + $query + self::DEFAULT_OPTIONS;

        $clientConnectionSettings = [
            'host' => $connectionCredentials['host'],
            'lang' => 'php',
            'pedantic' => false,
            'port' => $connectionCredentials['port'],
            'reconnect' => true,
            'timeout' => $configuration['max_batch_timeout'],
        ];

        if (isset($components['user']) && isset($components['pass']) && !empty($components['user']) && !empty($components['pass'])) {
            $clientConnectionSettings['user'] = $components['user'];
            $clientConnectionSettings['pass'] = $components['pass'];
        }

        list($streamName, $topic) = explode('/', substr($components['path'], 1));
        $nastConfig = new Configuration($clientConnectionSettings);
        $nastConfig->setDelay(floatval($configuration['delay']));
        $client = new Client($nastConfig);
        $this->topic = $topic;
        $this->streamName = $streamName;
        $this->client = $client;
        $this->configuration = $configuration;
    }
}
