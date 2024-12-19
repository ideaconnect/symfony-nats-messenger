<?php

namespace IDCT\NatsMessenger;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Ack;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Nak;
use Basis\Nats\Queue;
use Basis\Nats\Stream\Stream;
use Exception;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

class NatsTransport implements TransportInterface, MessageCountAwareInterface
{
    private const DEFAULT_OPTIONS = [
        'delay' => 0.001,
        'consumer' => 'client',
        'batching' => 10,
    ];

    protected Consumer $consumer;
    protected Queue $queue;
    protected Stream $stream;
    protected Client $client;
    protected string $topic;
    protected array $configuration;

    public function __construct(#[\SensitiveParameter] string $dsn, ?array $options = [])
    {
        $this->buildFromDsn($dsn, $options);
    }

    public function send(Envelope $envelope): Envelope
    {
        $uuid = (string) Uuid::v4();
        $envelope = $envelope->with(new TransportMessageIdStamp($uuid));
        $encodedMessage = igbinary_serialize($envelope);
        $this->client->publish($this->topic, $encodedMessage, 'r-' . $uuid);
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
                $message->nack();
                throw $e;
            }
        }

        return $envelopes;
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
        $this->client->connection->sendMessage(new Nak([
            'subject' => $id,
            'delay' => 0, //TODO
        ]));
    }

    public function getMessageCount(): int
    {
        $info = json_decode($this->consumer->info()->body);
        return $info->num_pending;
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
        ];
        if (isset($components['user']) && isset($components['pass']) && !empty($components['user']) && !empty($components['pass'])) {
            $clientConnectionSettings['user'] = $components['user'];
            $clientConnectionSettings['pass'] = $components['pass'];
        }

        list($stream, $topic) = explode('/', substr($components['path'], 1));
        $nastConfig = new Configuration($clientConnectionSettings);
        $nastConfig->setDelay(floatval($configuration['delay']));
        $client = new Client($nastConfig);
        $stream = $client->getApi()->getStream($stream);
        $consumer = $stream->getConsumer($configuration['consumer']);
        $consumer->setBatching($configuration['batching']);
        $this->topic = $topic;
        $this->consumer = $consumer;
        $this->client = $client;
        $this->stream = $stream;
        $this->configuration = $configuration;
        $this->queue = $consumer->getQueue();
        $this->queue->setTimeout($this->configuration['delay']);
    }
}