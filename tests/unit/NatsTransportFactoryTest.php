<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\NatsTransportFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class NatsTransportFactoryTest extends TestCase
{
    private NatsTransportFactory $factory;
    private SerializerInterface $mockSerializer;

    protected function setUp(): void
    {
        $this->factory = new NatsTransportFactory();
        $this->mockSerializer = $this->createMock(SerializerInterface::class);
    }

    #[Test]
    public function createTransport_WithValidDsn_ReturnsNatsTransportInstance(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = [];

        $transport = $this->factory->createTransport($dsn, $options, $this->mockSerializer);

        $this->assertInstanceOf(NatsTransport::class, $transport);

        // Verify DSN parts were actually parsed into the transport
        $reflection = new \ReflectionClass($transport);
        $streamName = $reflection->getProperty('streamName')->getValue($transport);
        $topic = $reflection->getProperty('topic')->getValue($transport);
        $this->assertSame('test-stream', $streamName);
        $this->assertSame('test-topic', $topic);
    }

    #[Test]
    public function createTransport_WithOptions_PassesOptionsToTransport(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = ['consumer' => 'my-consumer', 'batching' => 5];

        $transport = $this->factory->createTransport($dsn, $options, $this->mockSerializer);

        $this->assertInstanceOf(NatsTransport::class, $transport);

        // Verify options were applied to the transport's configuration
        $reflection = new \ReflectionClass($transport);
        $configuration = $reflection->getProperty('configuration')->getValue($transport);
        $this->assertSame('my-consumer', $configuration->consumer());
        $this->assertSame(5, $configuration->batching());
    }

    #[Test]
    public function createTransport_UsesProvidedSerializer(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = [];

        $transport = $this->factory->createTransport($dsn, $options, $this->mockSerializer);

        $reflection = new \ReflectionClass($transport);
        $serializerProperty = $reflection->getProperty('serializer');

        $this->assertInstanceOf(NatsTransport::class, $transport);
        $this->assertSame($this->mockSerializer, $serializerProperty->getValue($transport));
    }

    #[Test]
    public function supports_WithNatsJetStreamScheme_ReturnsTrue(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertTrue($result);
    }

    #[Test]
    public function supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue(): void
    {
        $dsn = 'nats-jetstream://user:password@localhost:4222/my-stream/my-topic?consumer=worker&batching=10';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertTrue($result);
    }

    #[Test]
    public function supports_WithNatsJetStreamTlsScheme_ReturnsTrue(): void
    {
        $dsn = 'nats-jetstream+tls://user:password@localhost:4222/my-stream/my-topic';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertTrue($result);
    }

    #[Test]
    public function supports_WithDifferentScheme_ReturnsFalse(): void
    {
        $dsn = 'redis://localhost:6379';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    #[Test]
    public function supports_WithNatsButNotJetStream_ReturnsFalse(): void
    {
        $dsn = 'nats://localhost:4222/test';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    #[Test]
    public function supports_WithAmqpScheme_ReturnsFalse(): void
    {
        $dsn = 'amqp://guest:guest@localhost:5672/';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    #[Test]
    public function supports_WithEmptyString_ReturnsFalse(): void
    {
        $dsn = '';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    #[Test]
    public function createTransport_WithDefaultPort_ParsesDsnCorrectly(): void
    {
        $dsn = 'nats-jetstream://localhost/my-stream/my-topic';

        $transport = $this->factory->createTransport($dsn, [], $this->mockSerializer);

        $reflection = new \ReflectionClass($transport);
        $this->assertSame('my-stream', $reflection->getProperty('streamName')->getValue($transport));
        $this->assertSame('my-topic', $reflection->getProperty('topic')->getValue($transport));
    }

    #[Test]
    public function createTransport_WithoutAuth_ParsesDsnCorrectly(): void
    {
        $dsn = 'nats-jetstream://localhost:4222/stream-no-auth/topic-no-auth';

        $transport = $this->factory->createTransport($dsn, [], $this->mockSerializer);

        $reflection = new \ReflectionClass($transport);
        $this->assertSame('stream-no-auth', $reflection->getProperty('streamName')->getValue($transport));
        $this->assertSame('topic-no-auth', $reflection->getProperty('topic')->getValue($transport));
    }

    #[Test]
    public function createTransport_WithQueryParams_ParsesConfigCorrectly(): void
    {
        $dsn = 'nats-jetstream://localhost:4222/test-stream/test-topic?consumer=worker&batching=10&stream_max_age=600';

        $transport = $this->factory->createTransport($dsn, [], $this->mockSerializer);

        $reflection = new \ReflectionClass($transport);
        $configuration = $reflection->getProperty('configuration')->getValue($transport);
        $this->assertSame('worker', $configuration->consumer());
        $this->assertSame(10, $configuration->batching());
        $this->assertSame(600, $configuration->streamMaxAgeSeconds());
    }

    #[Test]
    public function supports_WithHttpScheme_ReturnsFalse(): void
    {
        $result = $this->factory->supports('http://localhost:8080/path', []);

        $this->assertFalse($result);
    }
}
