<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\NatsTransportFactory;
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

    /**
     * @test
     */
    public function createTransport_WithValidDsn_ReturnsNatsTransportInstance(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = [];

        $transport = $this->factory->createTransport($dsn, $options, $this->mockSerializer);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function createTransport_WithOptions_PassesOptionsToTransport(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = ['consumer' => 'my-consumer', 'batching' => 5];

        $transport = $this->factory->createTransport($dsn, $options, $this->mockSerializer);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function createTransport_IgnoresProvidedSerializer(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = [];

        // The serializer should not be called - igbinary is used instead
        $this->mockSerializer->expects($this->never())->method('encode');
        $this->mockSerializer->expects($this->never())->method('decode');

        $transport = $this->factory->createTransport($dsn, $options, $this->mockSerializer);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function supports_WithNatsJetStreamScheme_ReturnsTrue(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue(): void
    {
        $dsn = 'nats-jetstream://user:password@localhost:4222/my-stream/my-topic?consumer=worker&batching=10';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function supports_WithDifferentScheme_ReturnsFalse(): void
    {
        $dsn = 'redis://localhost:6379';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function supports_WithNatsButNotJetStream_ReturnsFalse(): void
    {
        $dsn = 'nats://localhost:4222/test';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function supports_WithAmqpScheme_ReturnsFalse(): void
    {
        $dsn = 'amqp://guest:guest@localhost:5672/';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function supports_WithEmptyString_ReturnsFalse(): void
    {
        $dsn = '';
        $options = [];

        $result = $this->factory->supports($dsn, $options);

        $this->assertFalse($result);
    }
}
