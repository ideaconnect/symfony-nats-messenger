<?php

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\Enum\StorageBackend;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use PHPUnit\Framework\TestCase;

final class NatsTransportConfigurationTest extends TestCase
{
    public function testTypedAccessorsNormalizeScalarValues(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [
                'consumer' => 123,
                'batching' => '0',
                'max_batch_timeout' => '0.001',
                'stream_max_age' => '-5',
                'stream_max_bytes' => '1024',
                'stream_max_messages' => '500',
                'stream_max_messages_per_subject' => '25',
                'stream_storage' => 'memory',
                'stream_replicas' => '-1',
            ],
            natsRetryHandlerEnabled: true,
        );

        self::assertSame('123', $configuration->consumer());
        self::assertSame(1, $configuration->batching());
        self::assertSame(1, $configuration->maxBatchTimeoutMs());
        self::assertSame(0, $configuration->streamMaxAgeSeconds());
        self::assertSame(1024, $configuration->streamMaxBytes());
        self::assertSame(500, $configuration->streamMaxMessages());
        self::assertSame(25, $configuration->streamMaxMessagesPerSubject());
        self::assertSame(StorageBackend::Memory, $configuration->streamStorage());
        self::assertSame(1, $configuration->streamReplicas());
        self::assertTrue($configuration->isNatsRetryHandlerEnabled());
    }

    public function testTypedAccessorsProvideDefaults(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
        );

        self::assertSame('client', $configuration->consumer());
        self::assertSame(1, $configuration->batching());
        self::assertSame(1000, $configuration->maxBatchTimeoutMs());
        self::assertSame(0, $configuration->streamMaxAgeSeconds());
        self::assertNull($configuration->streamMaxBytes());
        self::assertNull($configuration->streamMaxMessages());
        self::assertNull($configuration->streamMaxMessagesPerSubject());
        self::assertSame(StorageBackend::File, $configuration->streamStorage());
        self::assertSame(1, $configuration->streamReplicas());
        self::assertFalse($configuration->isNatsRetryHandlerEnabled());
    }

    public function testTypedAccessorsTruncateFloatValues(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [
                'batching' => 3.7,
                'stream_max_bytes' => 2048.9,
                'stream_max_messages' => 100.1,
                'stream_max_messages_per_subject' => 7.9,
                'stream_replicas' => 2.5,
            ],
            natsRetryHandlerEnabled: false,
        );

        self::assertSame(3, $configuration->batching());
        self::assertSame(2048, $configuration->streamMaxBytes());
        self::assertSame(100, $configuration->streamMaxMessages());
        self::assertSame(7, $configuration->streamMaxMessagesPerSubject());
        self::assertSame(2, $configuration->streamReplicas());
    }

    public function testScheduledMessagesAccessorReturnsConstructorValue(): void
    {
        $enabled = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
            scheduledMessagesEnabled: true,
        );

        $disabled = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
            scheduledMessagesEnabled: false,
        );

        self::assertTrue($enabled->isScheduledMessagesEnabled());
        self::assertFalse($disabled->isScheduledMessagesEnabled());
    }

    public function testScheduledMessagesDefaultsToFalse(): void
    {
        $configuration = new NatsTransportConfiguration(
            topic: 'topic',
            streamName: 'stream',
            client: new NatsClient(),
            options: [],
            natsRetryHandlerEnabled: false,
        );

        self::assertFalse($configuration->isScheduledMessagesEnabled());
    }
}
