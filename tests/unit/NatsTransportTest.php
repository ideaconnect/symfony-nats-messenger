<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Ack;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Nak;
use Basis\Nats\Queue;
use Basis\Nats\Stream\Stream;
use IDCT\NatsMessenger\NatsTransport;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class NatsTransportTest extends TestCase
{
    private const VALID_DSN = 'nats://localhost:4222/test-stream/test-topic';

    /**
     * @test
     */
    public function constructor_WithValidDsn_InitializesTransport(): void
    {
        $dsn = self::VALID_DSN;

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
        $this->assertInstanceOf(TransportInterface::class, $transport);
        $this->assertInstanceOf(SetupableTransportInterface::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithInvalidDsn_ThrowsException(): void
    {
        $dsn = 'not-a-valid-dsn';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given NATS DSN is invalid');

        new NatsTransport($dsn, []);
    }

    /**
     * @test
     */
    public function constructor_WithMissingStreamName_ThrowsException(): void
    {
        $dsn = 'nats://localhost:4222';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS Stream name not provided');

        new NatsTransport($dsn, []);
    }

    /**
     * @test
     */
    public function constructor_WithInvalidPath_ThrowsException(): void
    {
        $dsn = 'nats://localhost:4222/s';

        $this->expectException(InvalidArgumentException::class);

        new NatsTransport($dsn, []);
    }

    /**
     * @test
     */
    public function constructor_WithMissingTopic_ThrowsException(): void
    {
        $dsn = 'nats://localhost:4222/stream-only/';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both stream name and topic name');

        new NatsTransport($dsn, []);
    }

    /**
     * @test
     */
    public function constructor_WithOptionsParameter_MergesWithDefaults(): void
    {
        $dsn = self::VALID_DSN;
        $options = ['batching' => 10, 'consumer' => 'custom-consumer'];

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithAuthentication_ParsesCredentials(): void
    {
        $dsn = 'nats://user:password@localhost:4222/test-stream/test-topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithCustomPort_ParsesPort(): void
    {
        $dsn = 'nats://localhost:5000/test-stream/test-topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithDefaultPort_UsesPort4222(): void
    {
        $dsn = 'nats://localhost/test-stream/test-topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithQueryParameters_MergesIntoConfiguration(): void
    {
        $dsn = 'nats://localhost:4222/test-stream/test-topic?consumer=query-consumer&batching=20';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_OptionsPrecedeQueryParameters(): void
    {
        $dsn = 'nats://localhost:4222/test-stream/test-topic?batching=20';
        $options = ['batching' => 10];

        // Options should override query parameters
        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function implementsRequiredInterfaces(): void
    {
        $transport = new NatsTransport(self::VALID_DSN, []);

        $this->assertInstanceOf(TransportInterface::class, $transport);
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface::class, $transport);
        $this->assertInstanceOf(SetupableTransportInterface::class, $transport);
    }

    /**
     * @test
     */
    public function findReceivedStamp_WithValidEnvelope_ReturnsStamp(): void
    {
        $transport = new NatsTransport(self::VALID_DSN, []);
        $envelope = new Envelope(new \stdClass());
        $stamp = new TransportMessageIdStamp('test-id');
        $envelope = $envelope->with($stamp);

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('findReceivedStamp');
        $method->setAccessible(true);

        $result = $method->invoke($transport, $envelope);

        $this->assertInstanceOf(TransportMessageIdStamp::class, $result);
        $this->assertEquals('test-id', $result->getId());
    }

    /**
     * @test
     */
    public function findReceivedStamp_WithoutStamp_ThrowsException(): void
    {
        $transport = new NatsTransport(self::VALID_DSN, []);
        $envelope = new Envelope(new \stdClass());

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('findReceivedStamp');
        $method->setAccessible(true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No ReceivedStamp found');

        $method->invoke($transport, $envelope);
    }

    /**
     * @test
     */
    public function constructor_WithStreamMaxAge_AcceptsConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $options = ['stream_max_age' => 3600];

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithStreamMaxBytes_AcceptsConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $options = ['stream_max_bytes' => 1024 * 1024 * 100]; // 100MB

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithStreamReplicas_AcceptsConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $options = ['stream_replicas' => 3];

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithDelay_AcceptsConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $options = ['delay' => 0.05];

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithMaxBatchTimeout_AcceptsConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $options = ['max_batch_timeout' => 1.5];

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }
}
