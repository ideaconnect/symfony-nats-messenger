<?php

namespace IDCT\NatsMessenger;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use InvalidArgumentException;
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

class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    private const DEFAULT_NATS_PORT = 4222;
    private const SECONDS_TO_NANOSECONDS = 1_000_000_000;
    private const MIN_PATH_LENGTH = 4;

    private const DEFAULT_OPTIONS = [
        'delay' => 0.01,
        'consumer' => 'client',
        'batching' => 1,
        'max_batch_timeout' => 1,
        'connection_timeout' => 1,
        'stream_max_age' => 0,
        'stream_max_bytes' => null,
        'stream_max_messages' => null,
        'stream_replicas' => 1,
    ];

    protected SerializerInterface $serializer;
    protected NatsClient $client;
    protected ?JetStreamContext $jetStream = null;
    protected string $topic;
    protected string $streamName;
    protected array $configuration;

    public function __construct(#[\SensitiveParameter] string $dsn, array $options, ?SerializerInterface $serializer = null)
    {
        if ($serializer === null && !$this->isExtensionLoaded('igbinary')) {
            trigger_error(
                'The igbinary extension is not installed. Please install ext-igbinary for optimal performance with the default serializer, or provide a custom serializer.',
            );
        }

        $this->serializer = $serializer ?? new IgbinarySerializer();

        $this->buildFromDsn($dsn, $options);
    }

    protected function isExtensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->connectIfNeeded();

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

        $payload = (string) ($encodedMessage['body'] ?? '');
        $headers = is_array($encodedMessage['headers'] ?? null) ? $encodedMessage['headers'] : [];

        if ($headers === []) {
            $this->jetStream->publish($this->topic, $payload)->await();
        } else {
            $normalizedHeaders = [];
            foreach ($headers as $name => $value) {
                $normalizedHeaders[(string) $name] = (string) $value;
            }

            $reply = $this->client->requestWithHeaders($this->topic, $payload, $normalizedHeaders)->await();
            $this->assertJetStreamPublishSucceeded($reply->payload);
        }

        return $envelope;
    }

    public function get(): iterable
    {
        $this->connectIfNeeded();

        try {
            $messages = $this->jetStream->fetchBatch(
                $this->streamName,
                (string) $this->configuration['consumer'],
                max(1, (int) $this->configuration['batching']),
                max(1, (int) round((float) $this->configuration['max_batch_timeout'] * 1000))
            )->await();
        } catch (JetStreamException $e) {
            if ($e->getCode() === 404 || $e->getCode() === 408) {
                return [];
            }

            throw $e;
        }

        $envelopes = [];

        foreach ($messages as $message) {
            if (!$message instanceof NatsMessage || $message->payload === '') {
                continue;
            }

            $headers = NatsHeaders::fromWireBlock($message->rawHeaders);

            try {
                $decoded = $this->serializer->decode([
                    'body' => $message->payload,
                    'headers' => $headers,
                ]);

                $envelopes[] = $decoded->with(new TransportMessageIdStamp((string) $message->replyTo));
            } catch (\Throwable $e) {
                if ($message->replyTo !== null && $message->replyTo !== '') {
                    $this->sendNak($message->replyTo);
                }

                throw $e;
            }
        }

        return $envelopes;
    }

    protected function sendNak(string $id): void
    {
        $this->connectIfNeeded();
        $this->jetStream->nak($this->buildAckMessage($id))->await();
    }

    public function ack(Envelope $envelope): void
    {
        $id = $this->findReceivedStamp($envelope)->getId();
        $this->connectIfNeeded();
        $this->jetStream->ack($this->buildAckMessage($id))->await();
    }

    public function reject(Envelope $envelope): void
    {
        $id = $this->findReceivedStamp($envelope)->getId();
        $this->sendNak($id);
    }

    protected function connect(): void
    {
        $this->client->connect()->await();
        $this->jetStream = $this->client->jetStream();
    }

    public function getMessageCount(): int
    {
        $this->connectIfNeeded();

        try {
            $consumerInfo = $this->jetStream->getConsumer($this->streamName, (string) $this->configuration['consumer'])->await();
            $ackPending = (int) ($consumerInfo->raw['num_ack_pending'] ?? 0);
            $pending = (int) ($consumerInfo->raw['num_pending'] ?? 0);

            return max($ackPending, $pending);
        } catch (\Throwable $e) {
            try {
                $streamInfo = $this->jetStream->getStream($this->streamName)->await();
                $state = is_array($streamInfo->raw['state'] ?? null) ? $streamInfo->raw['state'] : [];

                return (int) ($state['messages'] ?? 0);
            } catch (\Throwable $streamException) {
                return 0;
            }
        }
    }

    public function setup(): void
    {
        $this->connectIfNeeded();

        try {
            $streamOptions = [];

            if ((int) $this->configuration['stream_max_age'] > 0) {
                $streamOptions['max_age'] = (int) $this->configuration['stream_max_age'] * self::SECONDS_TO_NANOSECONDS;
            }

            if ($this->configuration['stream_max_bytes'] !== null) {
                $streamOptions['max_bytes'] = (int) $this->configuration['stream_max_bytes'];
            }

            if ($this->configuration['stream_max_messages'] !== null) {
                $streamOptions['max_msgs'] = (int) $this->configuration['stream_max_messages'];
            }

            if ((int) $this->configuration['stream_replicas'] > 0) {
                $streamOptions['num_replicas'] = (int) $this->configuration['stream_replicas'];
            }

            try {
                $this->jetStream->createStream($this->streamName, [$this->topic], $streamOptions)->await();
            } catch (\Throwable $streamCreateException) {
                $this->jetStream->updateStream($this->streamName, array_merge($streamOptions, [
                    'subjects' => [$this->topic],
                ]))->await();
            }

            $this->jetStream->createConsumer(
                $this->streamName,
                (string) $this->configuration['consumer'],
                $this->topic,
                [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                ]
            )->await();

            $consumerNames = array_map(
                static fn (object $consumer): string => (string) ($consumer->name ?? ''),
                $this->jetStream->listConsumers($this->streamName)->await()
            );

            if (!in_array((string) $this->configuration['consumer'], $consumerNames, true)) {
                throw new RuntimeException('Consumer was not created successfully.');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to setup NATS stream '{$this->streamName}': " . $e->getMessage(), 0, $e);
        }
    }

    private function findReceivedStamp(Envelope $envelope): TransportMessageIdStamp
    {
        /** @var TransportMessageIdStamp|null $receivedStamp */
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

        if (!isset($components['host'])) {
            throw new InvalidArgumentException('The given NATS DSN is invalid.');
        }

        $connectionCredentials = [
            'host' => $components['host'],
            'port' => $components['port'] ?? self::DEFAULT_NATS_PORT,
        ];

        if (!isset($components['path'])) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        $path = $components['path'];
        if ($path === '' || strlen($path) < self::MIN_PATH_LENGTH) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        $configuration = [];
        $configuration += $options + $query + self::DEFAULT_OPTIONS;

        $pathParts = explode('/', substr($components['path'], 1));
        if (count($pathParts) < 2 || $pathParts[0] === '' || $pathParts[1] === '') {
            throw new InvalidArgumentException('NATS DSN must contain both stream name and topic name (format: /stream/topic).');
        }

        [$streamName, $topic] = $pathParts;

        $scheme = ($components['scheme'] ?? 'nats') === 'tls' ? 'tls' : 'nats';
        $server = sprintf('%s://%s:%d', $scheme, $connectionCredentials['host'], (int) $connectionCredentials['port']);

        $client = new NatsClient(new NatsOptions(
            servers: [$server],
            connectTimeoutMs: max(1, (int) round((float) $configuration['connection_timeout'] * 1000)),
            pedantic: false,
            reconnectEnabled: false,
            username: isset($components['user']) && $components['user'] !== '' ? $components['user'] : null,
            password: isset($components['pass']) && $components['pass'] !== '' ? $components['pass'] : null,
        ));

        $this->topic = $topic;
        $this->streamName = $streamName;
        $this->client = $client;
        $this->configuration = $configuration;
    }

    private function connectIfNeeded(): void
    {
        if ($this->jetStream === null) {
            $this->connect();
        }
    }

    private function buildAckMessage(string $replyTo): NatsMessage
    {
        return new NatsMessage(
            subject: $this->topic,
            sid: 0,
            replyTo: $replyTo,
            payload: ''
        );
    }

    private function assertJetStreamPublishSucceeded(string $payload): void
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return;
        }

        if (is_array($decoded['error'] ?? null)) {
            $description = (string) ($decoded['error']['description'] ?? 'JetStream publish error');
            $code = (int) ($decoded['error']['code'] ?? 0);
            throw new RuntimeException($description, $code);
        }
    }
}
