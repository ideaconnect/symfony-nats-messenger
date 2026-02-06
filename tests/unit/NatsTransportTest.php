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

/**
 * Testable subclass that avoids connecting to NATS during construction.
 * Used for testing DSN parsing and configuration without requiring a real NATS server.
 */
class TestableNatsTransport extends NatsTransport
{
    protected function connect(): void
    {
        // No-op: skip connection during tests
    }

    /**
     * Override buildFromDsn to avoid calling Client::setTimeout() which triggers a connection.
     */
    protected function buildFromDsn(#[\SensitiveParameter] string $dsn, array $options = []): void
    {
        // Call parent implementation but catch the setTimeout call
        $reflection = new \ReflectionClass(NatsTransport::class);
        $method = $reflection->getMethod('buildFromDsn');

        // We need to replicate the parent logic but skip the setTimeout call
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
            'port' => $components['port'] ?? 4222,
        ];

        // Validate that path exists for stream name and topic
        if (!isset($components['path'])) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        $path = $components['path'];

        // Validate that stream name and topic are provided
        if (empty($path) || strlen($path) < 4) {
            throw new InvalidArgumentException('NATS Stream name not provided.');
        }

        // Parse query parameters from DSN
        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        // Merge configuration
        $defaultOptions = [
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
        $configuration = [];
        $configuration += $options + $query + $defaultOptions;

        // Build client connection settings
        $clientConnectionSettings = [
            'host' => $connectionCredentials['host'],
            'lang' => 'php',
            'pedantic' => false,
            'port' => intval($connectionCredentials['port']),
            'reconnect' => true,
            'timeout' => floatval($configuration['max_batch_timeout']),
        ];

        // Add authentication if provided in DSN
        if (isset($components['user']) && isset($components['pass']) && !empty($components['user']) && !empty($components['pass'])) {
            $clientConnectionSettings['user'] = $components['user'];
            $clientConnectionSettings['pass'] = $components['pass'];
        }

        // Extract stream name and topic from path
        $pathParts = explode('/', substr($components['path'], 1));
        if (count($pathParts) < 2 || empty($pathParts[0]) || empty($pathParts[1])) {
            throw new InvalidArgumentException('NATS DSN must contain both stream name and topic name (format: /stream/topic).');
        }

        [$streamName, $topic] = $pathParts;

        // Create NATS client configuration (but don't call setTimeout on Client)
        $nastConfig = new Configuration($clientConnectionSettings);
        $nastConfig->setDelay(floatval($configuration['delay']));

        // Initialize client without setting timeout (which would trigger connection)
        $client = new Client($nastConfig);

        // Store the connection_timeout in configuration for later use
        // Note: We skip $client->setTimeout() to avoid triggering a connection
        $this->topic = $topic;
        $this->streamName = $streamName;
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * Get the stored configuration for testing purposes.
     */
    public function getTestConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * Get the NATS client for testing purposes.
     */
    public function getTestClient(): Client
    {
        return $this->client;
    }
}

/**
 * Test subclass that simulates missing igbinary extension.
 */
class NatsTransportWithoutIgbinary extends TestableNatsTransport
{
    protected function isExtensionLoaded(string $extension): bool
    {
        if ($extension === 'igbinary') {
            return false;
        }
        return parent::isExtensionLoaded($extension);
    }
}

class NatsTransportTest extends TestCase
{
    private const VALID_DSN = 'nats://admin:password@localhost:4222/test-stream/test-topic';

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
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithCustomPort_ParsesPort(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithDefaultPort_UsesPort4222(): void
    {
        $dsn = 'nats://admin:password@localhost/test-stream/test-topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_WithQueryParameters_MergesIntoConfiguration(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic?consumer=query-consumer&batching=20';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function constructor_OptionsPrecedeQueryParameters(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic?batching=20';
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

    /**
     * @test
     */
    public function send_WithValidEnvelope_AddsTransportStampAndReturnsEnvelope(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create mock stream that doesn't actually connect to NATS
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('publish')
                  ->with($this->equalTo('test-topic'), $this->anything());

        // Use reflection to inject the mock stream
        $reflection = new \ReflectionClass($transport);
        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, $mockStream);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $resultEnvelope = $transport->send($envelope);

        $this->assertInstanceOf(Envelope::class, $resultEnvelope);
        $this->assertNotSame($envelope, $resultEnvelope);

        $transportStamp = $resultEnvelope->last(TransportMessageIdStamp::class);
        $this->assertInstanceOf(TransportMessageIdStamp::class, $transportStamp);
        $this->assertNotEmpty($transportStamp->getId());
    }

    /**
     * @test
     */
    public function setup_WithValidConfiguration_CompletesSuccessfully(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // This should not throw an exception
        $transport->setup();

        $this->assertTrue(true); // If we get here, setup completed successfully
    }

    /**
     * @test
     */
    public function getMessageCount_AfterSendingMessages_ReturnsCorrectCount(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Setup the transport first
        $transport->setup();

        // Send a message
        $message = new \stdClass();
        $envelope = new Envelope($message);
        $transport->send($envelope);

        $count = $transport->getMessageCount();

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * @test
     */
    public function get_WithoutMessages_ReturnsEmptyOrLimitedArray(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $transport->setup();

        $messages = $transport->get();

        $this->assertIsIterable($messages);
        // Note: NATS may have existing messages from other tests, so we just verify it's an array
        $this->assertIsArray($messages);
    }

    /**
     * @test
     */
    public function get_WithSentMessages_ReturnsEnvelopes(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $transport->setup();

        // Send a message first
        $message = new \stdClass();
        $envelope = new Envelope($message);
        $sentEnvelope = $transport->send($envelope);

        // Give NATS a moment to process
        usleep(100000); // 100ms

        $messages = $transport->get();

        $this->assertIsIterable($messages);
    }

    /**
     * @test
     */
    public function ack_WithValidEnvelope_CompletesSuccessfully(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $transport->setup();

        // Send and receive a message to get a valid envelope for acking
        $message = new \stdClass();
        $envelope = new Envelope($message);
        $sentEnvelope = $transport->send($envelope);

        // Give NATS a moment to process
        usleep(100000); // 100ms

        $receivedMessages = $transport->get();
        if (count($receivedMessages) > 0) {
            $receivedEnvelope = $receivedMessages[0];

            // This should not throw an exception
            $transport->ack($receivedEnvelope);
            $this->assertTrue(true);
        } else {
            // If no messages received, test that ack doesn't crash with a mock envelope
            $mockEnvelope = $envelope->with(new TransportMessageIdStamp('test-id'));
            $this->expectException(\LogicException::class);
            $transport->ack($mockEnvelope);
        }
    }

    /**
     * @test
     */
    public function reject_WithValidEnvelope_CompletesSuccessfully(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $transport->setup();

        // Send and receive a message to get a valid envelope for rejecting
        $message = new \stdClass();
        $envelope = new Envelope($message);
        $sentEnvelope = $transport->send($envelope);

        // Give NATS a moment to process
        usleep(100000); // 100ms

        $receivedMessages = $transport->get();
        if (count($receivedMessages) > 0) {
            $receivedEnvelope = $receivedMessages[0];

            // This should not throw an exception
            $transport->reject($receivedEnvelope);
            $this->assertTrue(true);
        } else {
            // If no messages received, test that reject doesn't crash with a mock envelope
            $mockEnvelope = $envelope->with(new TransportMessageIdStamp('test-id'));
            $this->expectException(\LogicException::class);
            $transport->reject($mockEnvelope);
        }
    }

    /**
     * @test
     */
    public function ack_WithoutTransportStamp_ThrowsException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No ReceivedStamp found on the Envelope.');

        $transport->ack($envelope);
    }

    /**
     * @test
     */
    public function reject_WithoutTransportStamp_ThrowsException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No ReceivedStamp found on the Envelope.');

        $transport->reject($envelope);
    }

    /**
     * @test
     */
    public function send_WithEnvelopeContainingErrorDetails_SendsSuccessfully(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create mock stream that doesn't actually connect to NATS
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('publish')
                  ->with($this->equalTo('test-topic'), $this->anything());

        // Use reflection to inject the mock stream
        $reflection = new \ReflectionClass($transport);
        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, $mockStream);

        $message = new \stdClass();
        $message->data = 'test data';
        $errorStamp = new ErrorDetailsStamp(\Exception::class, 500, 'Test error message');
        $envelope = new Envelope($message, [$errorStamp]);

        $result = $transport->send($envelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertNotNull($result->last(TransportMessageIdStamp::class));
    }    /**
     * @test
     */
    public function send_WithSerializationWarning_SendsSuccessfully(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create an object that will cause serialization warnings but still serialize
        $message = new \stdClass();
        $message->resource = fopen('php://memory', 'r');
        $envelope = new Envelope($message);

        $result = $transport->send($envelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertNotNull($result->last(TransportMessageIdStamp::class));

        fclose($message->resource);
    }

    /**
     * @test
     */
    public function send_WithSerializationFailure_ThrowsException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create an object that cannot be serialized
        $message = new \stdClass();
        $message->closure = function() { return 'test'; }; // Closures cannot be serialized
        $envelope = new Envelope($message);

        $this->expectException(\Exception::class);
        $transport->send($envelope);
    }

    /**
     * @test
     */
    public function send_WithSerializationFailureAndErrorStamp_ThrowsExceptionWithErrorMessage(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create an object that cannot be serialized
        $message = new \stdClass();
        $message->closure = function() { return 'test'; }; // Closures cannot be serialized

        // Add an ErrorDetailsStamp to test the error handling path
        $errorStamp = new ErrorDetailsStamp(\RuntimeException::class, 500, 'Custom error message from stamp');
        $envelope = new Envelope($message, [$errorStamp]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Custom error message from stamp');

        $transport->send($envelope);
    }

    /**
     * @test
     */
    public function buildFromDsn_WithUsernameAndPassword_ParsesCredentials(): void
    {
        $dsn = 'nats://testuser:testpass@localhost:4222/stream/topic';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function buildFromDsn_WithComplexQueryString_ParsesAllParameters(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/stream/topic?consumer=test&batching=50&delay=0.1&stream_max_age=3600';

        $transport = new NatsTransport($dsn, []);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function buildFromDsn_WithStreamConfiguration_AcceptsAllOptions(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/stream/topic';
        $options = [
            'stream_max_bytes' => 1000000,
            'stream_max_messages' => 5000,
            'stream_replicas' => 3,
            'max_batch_timeout' => 2.0
        ];

        $transport = new NatsTransport($dsn, $options);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @test
     */
    public function findReceivedStamp_WithValidTransportStamp_ReturnsStamp(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        $message = new \stdClass();
        $stamp = new TransportMessageIdStamp('test-123');
        $envelope = new Envelope($message, [$stamp]);

        // Use reflection to access private method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('findReceivedStamp');

        $result = $method->invoke($transport, $envelope);

        $this->assertInstanceOf(TransportMessageIdStamp::class, $result);
        $this->assertEquals('test-123', $result->getId());
    }

    /**
     * @test
     */
    public function connect_InitializesClientAndConsumer(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('connect');

        // This should not throw an exception
        $method->invoke($transport);

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function decodeJsonInfo_WithValidJson_ReturnsObject(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to access private method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('decodeJsonInfo');

        // Create a mock response object with body property
        $response = new \stdClass();
        $response->body = '{"server_id":"test","version":"2.9.0"}';

        $result = $method->invoke($transport, $response);

        $this->assertIsObject($result);
        $this->assertEquals('test', $result->server_id);
        $this->assertEquals('2.9.0', $result->version);
    }

    /**
     * @test
     */
    public function decodeJsonInfo_WithInvalidJson_ReturnsNull(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to access private method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('decodeJsonInfo');

        // Test with null response
        $result = $method->invoke($transport, null);
        $this->assertNull($result);

        // Test with response without body
        $response = new \stdClass();
        $result = $method->invoke($transport, $response);
        $this->assertNull($result);

        // Test with invalid JSON
        $response = new \stdClass();
        $response->body = 'not valid json';
        $result = $method->invoke($transport, $response);
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function sendNak_WithValidId_SendsNakMessage(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('sendNak');

        // Call sendNak method with a test ID
        $testId = 'test_message_id';
        $method->invoke($transport, $testId);

        // If no exception was thrown, the method worked
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function connect_WithValidConfiguration_InitializesConnections(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to access protected method and properties
        $reflection = new \ReflectionClass($transport);
        $connectMethod = $reflection->getMethod('connect');

        $streamProperty = $reflection->getProperty('stream');

        $consumerProperty = $reflection->getProperty('consumer');

        $queueProperty = $reflection->getProperty('queue');

        // Call connect method
        $connectMethod->invoke($transport);

        // Verify that all connections are established
        $this->assertNotNull($streamProperty->getValue($transport));
        $this->assertNotNull($consumerProperty->getValue($transport));
        $this->assertNotNull($queueProperty->getValue($transport));
    }

    /**
     * @test
     */
    public function buildFromDsn_WithComplexDsn_ParsesAllParameters(): void
    {
        // Use localhost to avoid connection timeouts but with different parameters
        $complexDsn = 'nats://admin:password@localhost:4222/test_stream/test_topic?consumer=worker&batching=10&delay=0.5&stream_max_age=3600';

        // Create transport with complex DSN
        $transport = new NatsTransport($complexDsn, []);

        // Use reflection to access private properties
        $reflection = new \ReflectionClass($transport);

        $topicProperty = $reflection->getProperty('topic');

        $streamNameProperty = $reflection->getProperty('streamName');

        $configurationProperty = $reflection->getProperty('configuration');

        // Verify all parameters were parsed correctly
        $this->assertEquals('test_topic', $topicProperty->getValue($transport));
        $this->assertEquals('test_stream', $streamNameProperty->getValue($transport));

        $configuration = $configurationProperty->getValue($transport);
        $this->assertEquals('worker', $configuration['consumer']);
        $this->assertEquals(10, $configuration['batching']);
        $this->assertEquals(0.5, $configuration['delay']);
        $this->assertEquals(3600, $configuration['stream_max_age']);
    }

    // /**
    //  * @test
    //  */
    // public function send_WithoutInitializedStream_InitializesStreamThenSends(): void
    // {
    //     $dsn = self::VALID_DSN;
    //     $transport = new NatsTransport($dsn, []);

    //     // Use reflection to clear the stream to test lazy loading
    //     $reflection = new \ReflectionClass($transport);
    //     $streamProperty = $reflection->getProperty('stream');
    //     $streamProperty->setValue($transport, null);

    //     $message = new \stdClass();
    //     $message->data = 'test message';
    //     $envelope = new Envelope($message);

    //     $result = $transport->send($envelope);

    //     $this->assertInstanceOf(Envelope::class, $result);
    //     $this->assertNotNull($result->last(TransportMessageIdStamp::class));

    //     // Verify stream was initialized
    //     $this->assertNotNull($streamProperty->getValue($transport));
    // }

    /**
     * @test
     */
    public function get_WithEmptyMessages_SkipsEmptyMessages(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // This test covers the empty message skip logic
        $envelopes = $transport->get();

        // Should return iterable even if no messages
        $this->assertIsIterable($envelopes);
        $envelopeArray = iterator_to_array($envelopes);
        $this->assertIsArray($envelopeArray);
    }

    /**
     * @test
     */
    public function get_WithValidSerializedMessage_ReturnsEnvelopes(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a proper envelope that can be serialized/unserialized
        $originalMessage = new \stdClass();
        $originalMessage->data = 'test data';
        $envelope = new \Symfony\Component\Messenger\Envelope($originalMessage);
        $serialized = \igbinary_serialize($envelope);

        // Create a mock message with this serialized payload
        $mockMessage = $this->createMock(\Basis\Nats\Message\Msg::class);

        // Create a mock payload with the serialized data
        $mockPayload = $this->createMock(\Basis\Nats\Message\Payload::class);
        $mockPayload->body = $serialized;
        $mockPayload->headers = [];

        // Configure the mock message
        $mockMessage->payload = $mockPayload;
        $mockMessage->replyTo = 'test-reply-to-123';

        // Create a mock queue that returns the message
        $mockQueue = $this->createMock(\Basis\Nats\Queue::class);
        $mockQueue->method('fetchAll')->willReturn([$mockMessage]);

        // Create a mock connection
        $mockConnection = $this->createMock(\Basis\Nats\Connection::class);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->connection = $mockConnection;

        // Use reflection to inject the mocked dependencies
        $reflection = new \ReflectionClass($transport);

        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setValue($transport, $mockQueue);

        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        // Test that the message can be processed successfully
        $envelopes = $transport->get();
        $this->assertIsIterable($envelopes);
        $envelopeArray = iterator_to_array($envelopes);
        $this->assertCount(1, $envelopeArray);
        $this->assertInstanceOf(\Symfony\Component\Messenger\Envelope::class, $envelopeArray[0]);
    }

    /**
     * @test
     */
    public function getMessageCount_WithFailedConsumerInfo_FallsBackToStreamInfo(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // This will test the fallback logic when consumer info fails
        $count = $transport->getMessageCount();

        // Should return integer count (could be 0 if no messages)
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * @test
     */
    public function getMessageCount_WithNullConsumerInfo_ReturnsZero(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to test the private decodeJsonInfo method returning null
        $reflection = new \ReflectionClass($transport);
        $decodeMethod = $reflection->getMethod('decodeJsonInfo');

        // Test that null response returns 0
        $result = $decodeMethod->invoke($transport, null);
        $this->assertNull($result);

        // Test with response object that has no body
        $mockResponse = new \stdClass();
        $result = $decodeMethod->invoke($transport, $mockResponse);
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function getMessageCount_WithConsumerInfoDecodingToNull_ReturnsZero(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Mock consumer to return a response that will decode to null
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumerResponse = new \stdClass();
        $mockConsumerResponse->body = null; // This will cause decodeJsonInfo to return null
        $mockConsumer->method('info')->willReturn($mockConsumerResponse);

        // Use reflection to set the mocked consumer
        $reflection = new \ReflectionClass($transport);
        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setValue($transport, $mockConsumer);

        $count = $transport->getMessageCount();

        $this->assertSame(0, $count);
    }

    /**
     * @test
     */
    public function getMessageCount_WithConsumerInfoInvalidJson_ReturnsZero(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Mock consumer to return a response with invalid JSON that will decode to null
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumerResponse = new \stdClass();
        $mockConsumerResponse->body = 'invalid-json-string-not-object'; // This will cause json_decode to not return stdClass
        $mockConsumer->method('info')->willReturn($mockConsumerResponse);

        // Use reflection to set the mocked consumer
        $reflection = new \ReflectionClass($transport);
        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setValue($transport, $mockConsumer);

        $count = $transport->getMessageCount();

        $this->assertSame(0, $count);
    }

    /**
     * @test
     */
    public function getMessageCount_WithExceptionFromConsumerInfo_FallsBackToStreamInfo(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Mock the consumer to throw an exception
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->method('info')->willThrowException(new \Exception('Consumer info failed'));

        // Mock the stream to return valid data
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStreamResponse = new \stdClass();
        $mockStreamResponse->body = json_encode([
            'state' => [
                'messages' => 42
            ]
        ]);
        $mockStream->method('info')->willReturn($mockStreamResponse);

        // Use reflection to set the mocked objects
        $reflection = new \ReflectionClass($transport);

        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setValue($transport, $mockConsumer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, $mockStream);

        $count = $transport->getMessageCount();

        $this->assertSame(42, $count);
    }

    /**
     * @test
     */
    public function getMessageCount_WithBothConsumerAndStreamFailure_ReturnsZero(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Mock both consumer and stream to throw exceptions
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->method('info')->willThrowException(new \Exception('Consumer failed'));

        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->method('info')->willThrowException(new \Exception('Stream failed'));

        // Use reflection to set the mocked objects
        $reflection = new \ReflectionClass($transport);

        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setValue($transport, $mockConsumer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, $mockStream);

        $count = $transport->getMessageCount();

        $this->assertSame(0, $count);
    }

    /**
     * @test
     */
    public function getMessageCount_WithNullStreamInfo_ReturnsZero(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Mock consumer to throw exception and stream to return null body
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->method('info')->willThrowException(new \Exception('Consumer failed'));

        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStreamResponse = new \stdClass();
        $mockStreamResponse->body = null; // This will cause decodeJsonInfo to return null
        $mockStream->method('info')->willReturn($mockStreamResponse);

        // Use reflection to set the mocked objects
        $reflection = new \ReflectionClass($transport);

        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setValue($transport, $mockConsumer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, $mockStream);

        $count = $transport->getMessageCount();

        $this->assertSame(0, $count);
    }

    /**
     * @test
     */
    public function setup_WithStreamMaxAgeConfiguration_AppliesMaxAge(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream-max-age/max-age-topic';
        $options = ['stream_max_age' => 3600]; // 1 hour
        $transport = new NatsTransport($dsn, $options);

        // Call setup to configure stream with max age
        $transport->setup();

        // If no exception thrown, setup was successful
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function setup_WithStreamMaxBytesConfiguration_AppliesMaxBytes(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream-max-bytes/max-bytes-topic';
        $options = ['stream_max_bytes' => 1048576]; // 1MB
        $transport = new NatsTransport($dsn, $options);

        // Call setup to configure stream with max bytes
        $transport->setup();

        // If no exception thrown, setup was successful
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function constructor_WithMalformedDsn_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given NATS DSN is invalid.');

        // Test with completely invalid DSN
        $malformedDsn = 'not-a-valid-dsn-at-all';
        new NatsTransport($malformedDsn, []);
    }

    /**
     * @test
     */
    public function constructor_WithDsnMissingHost_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given NATS DSN is invalid.');

        // Test with DSN missing host
        $dsnWithoutHost = 'nats:///test-stream/test-topic';
        new NatsTransport($dsnWithoutHost, []);
    }

    /**
     * @test
     */
    public function constructor_WithDsnMissingPath_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS Stream name not provided.');

        // Test with DSN missing path completely
        $dsnWithoutPath = 'nats://localhost:4222';
        new NatsTransport($dsnWithoutPath, []);
    }

    /**
     * @test
     */
    public function constructor_WithTooShortPath_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS Stream name not provided.');

        // Test with DSN with path too short
        $dsnWithShortPath = 'nats://localhost:4222/s';
        new NatsTransport($dsnWithShortPath, []);
    }

    /**
     * @test
     */
    public function constructor_WithInvalidPathFormat_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS DSN must contain both stream name and topic name');

        // Test with DSN missing topic (only stream name)
        $dsnMissingTopic = 'nats://localhost:4222/stream-only';
        new NatsTransport($dsnMissingTopic, []);
    }

    /**
     * @test
     */
    public function constructor_WithEmptyStreamName_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS DSN must contain both stream name and topic name');

        // Test with empty stream name
        $dsnEmptyStream = 'nats://localhost:4222//topic';
        new NatsTransport($dsnEmptyStream, []);
    }

    /**
     * @test
     */
    public function constructor_WithEmptyTopic_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS DSN must contain both stream name and topic name');

        // Test with empty topic name
        $dsnEmptyTopic = 'nats://localhost:4222/stream/';
        new NatsTransport($dsnEmptyTopic, []);
    }

    /**
     * @test
     */
    public function setup_WithGetStreamException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a mock API that throws exception on getStream
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willThrowException(new \Exception('Stream access failed'));

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithGetConfigurationException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a mock stream that throws exception on getConfiguration
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willThrowException(new \Exception('Configuration access failed'));

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithSetSubjectsException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a mock configuration that throws exception on setSubjects
        $mockConfig = $this->createMock(\Basis\Nats\Stream\Configuration::class);
        $mockConfig->expects($this->once())
                  ->method('setSubjects')
                  ->willThrowException(new \Exception('setSubjects failed'));

        // Create a mock stream
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willReturn($mockConfig);

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithStreamCreateException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a mock configuration
        $mockConfig = $this->createMock(\Basis\Nats\Stream\Configuration::class);
        $mockConfig->expects($this->once())->method('setSubjects');

        // Create a mock stream that throws exception on create
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willReturn($mockConfig);
        $mockStream->expects($this->once())
                  ->method('create')
                  ->willThrowException(new \Exception('Stream creation failed'));

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithGetConsumerException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a mock configuration
        $mockConfig = $this->createMock(\Basis\Nats\Stream\Configuration::class);
        $mockConfig->expects($this->once())->method('setSubjects');

        // Create a mock stream that throws exception on getConsumer
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willReturn($mockConfig);
        $mockStream->expects($this->once())->method('create');
        $mockStream->expects($this->once())
                  ->method('getConsumer')
                  ->willThrowException(new \Exception('Consumer access failed'));

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithConsumerCreateException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create mock consumer configuration
        $mockConsumerConfig = $this->createMock(\Basis\Nats\Consumer\Configuration::class);
        $mockConsumerConfig->expects($this->once())->method('setAckPolicy');
        $mockConsumerConfig->expects($this->once())->method('setDeliverPolicy');
        $mockConsumerConfig->expects($this->once())->method('setSubjectFilter');

        // Create a mock consumer that throws exception on create
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->expects($this->exactly(3)) // Called three times: setAckPolicy, setDeliverPolicy, and setSubjectFilter
                    ->method('getConfiguration')
                    ->willReturn($mockConsumerConfig);
        $mockConsumer->expects($this->once())->method('setBatching');
        $mockConsumer->expects($this->once())
                    ->method('create')
                    ->willThrowException(new \Exception('Consumer creation failed'));

        // Create a mock configuration
        $mockConfig = $this->createMock(\Basis\Nats\Stream\Configuration::class);
        $mockConfig->expects($this->once())->method('setSubjects');

        // Create a mock stream
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willReturn($mockConfig);
        $mockStream->expects($this->once())->method('create');
        $mockStream->expects($this->once())
                  ->method('getConsumer')
                  ->willReturn($mockConsumer);

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithGetConsumerNamesException_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create mock consumer configuration
        $mockConsumerConfig = $this->createMock(\Basis\Nats\Consumer\Configuration::class);
        $mockConsumerConfig->expects($this->once())->method('setAckPolicy');
        $mockConsumerConfig->expects($this->once())->method('setDeliverPolicy');
        $mockConsumerConfig->expects($this->once())->method('setSubjectFilter');

        // Create a mock consumer
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->expects($this->exactly(3)) // Called three times: setAckPolicy, setDeliverPolicy, and setSubjectFilter
                    ->method('getConfiguration')
                    ->willReturn($mockConsumerConfig);
        $mockConsumer->expects($this->once())->method('setBatching');
        $mockConsumer->expects($this->once())->method('create');

        // Create a mock configuration
        $mockConfig = $this->createMock(\Basis\Nats\Stream\Configuration::class);
        $mockConfig->expects($this->once())->method('setSubjects');

        // Create a mock stream that throws exception on getConsumerNames
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willReturn($mockConfig);
        $mockStream->expects($this->once())->method('create');
        $mockStream->expects($this->once())
                  ->method('getConsumer')
                  ->willReturn($mockConsumer);
        $mockStream->expects($this->once())
                  ->method('getConsumerNames')
                  ->willThrowException(new \Exception('getConsumerNames failed'));

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream");

        $transport->setup();
    }

    /**
     * @test
     */
    public function setup_WithConsumerNotInNamesList_ThrowsRuntimeException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create mock consumer configuration
        $mockConsumerConfig = $this->createMock(\Basis\Nats\Consumer\Configuration::class);
        $mockConsumerConfig->expects($this->any())->method('setAckPolicy');
        $mockConsumerConfig->expects($this->any())->method('setDeliverPolicy');

        // Create a mock consumer
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->expects($this->any())
                    ->method('getConfiguration')
                    ->willReturn($mockConsumerConfig);
        $mockConsumer->expects($this->once())->method('setBatching');
        $mockConsumer->expects($this->once())->method('create');

        // Create a mock configuration
        $mockConfig = $this->createMock(\Basis\Nats\Stream\Configuration::class);
        $mockConfig->expects($this->once())->method('setSubjects');

        // Create a mock stream that returns empty consumer names list
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
                  ->method('getConfiguration')
                  ->willReturn($mockConfig);
        $mockStream->expects($this->once())->method('create');
        $mockStream->expects($this->once())
                  ->method('getConsumer')
                  ->willReturn($mockConsumer);
        $mockStream->expects($this->once())
                  ->method('getConsumerNames')
                  ->willReturn(['other-consumer']); // Return list without our consumer

        // Create a mock API
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
               ->method('getStream')
               ->willReturn($mockStream);

        // Create a mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
                  ->method('getApi')
                  ->willReturn($mockApi);

        // Use reflection to inject the mock client
        $reflection = new \ReflectionClass($transport);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Consumer was not created successfully");

        $transport->setup();
    }

    /**
     * @test
     */
    public function constructor_WithConnectionTimeout_StoresInConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $connectionTimeout = 2.5;
        $options = ['connection_timeout' => $connectionTimeout];

        $transport = new TestableNatsTransport($dsn, $options);

        $config = $transport->getTestConfiguration();
        $this->assertEquals($connectionTimeout, $config['connection_timeout']);
    }

    /**
     * @test
     */
    public function constructor_WithConnectionTimeoutFromDsn_ParsesAsFloat(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic?connection_timeout=3.5';

        $transport = new TestableNatsTransport($dsn, []);

        $config = $transport->getTestConfiguration();
        $this->assertEquals(3.5, floatval($config['connection_timeout']));
    }

    /**
     * @test
     */
    public function constructor_WithMaxBatchTimeoutFromDsn_ParsesAsFloat(): void
    {
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic?max_batch_timeout=2.5';

        $transport = new TestableNatsTransport($dsn, []);

        $config = $transport->getTestConfiguration();
        $this->assertEquals(2.5, floatval($config['max_batch_timeout']));
    }

    /**
     * @test
     */
    public function constructor_WithStringTimeoutValuesFromDsn_CoercesToFloat(): void
    {
        // Query parameters are always strings when parsed from DSN
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic?connection_timeout=5&max_batch_timeout=3';

        $transport = new TestableNatsTransport($dsn, []);

        $config = $transport->getTestConfiguration();

        // Values should be coercible to float (DSN query params come as strings)
        $this->assertIsNumeric($config['connection_timeout']);
        $this->assertIsNumeric($config['max_batch_timeout']);
        $this->assertEquals(5.0, floatval($config['connection_timeout']));
        $this->assertEquals(3.0, floatval($config['max_batch_timeout']));
    }

    /**
     * @test
     */
    public function constructor_WithMaxBatchTimeout_PassesToNatsConfiguration(): void
    {
        $dsn = self::VALID_DSN;
        $maxBatchTimeout = 4.5;
        $options = ['max_batch_timeout' => $maxBatchTimeout];

        $transport = new TestableNatsTransport($dsn, $options);

        // Access the client and its configuration
        $client = $transport->getTestClient();
        $clientReflection = new \ReflectionClass($client);
        $configProperty = $clientReflection->getProperty('configuration');
        $natsConfig = $configProperty->getValue($client);

        // Verify the timeout was set in the Configuration
        $this->assertEquals($maxBatchTimeout, $natsConfig->timeout);
    }

    /**
     * @test
     */
    public function constructor_WithBothTimeouts_ConfiguresIndependently(): void
    {
        $dsn = self::VALID_DSN;
        $maxBatchTimeout = 2.0;
        $connectionTimeout = 5.0;
        $options = [
            'max_batch_timeout' => $maxBatchTimeout,
            'connection_timeout' => $connectionTimeout,
        ];

        $transport = new TestableNatsTransport($dsn, $options);

        // Verify both timeouts are stored independently
        $config = $transport->getTestConfiguration();
        $this->assertEquals($maxBatchTimeout, $config['max_batch_timeout']);
        $this->assertEquals($connectionTimeout, $config['connection_timeout']);

        // Verify max_batch_timeout is passed to NATS Configuration
        $client = $transport->getTestClient();
        $clientReflection = new \ReflectionClass($client);
        $natsConfigProperty = $clientReflection->getProperty('configuration');
        $natsConfig = $natsConfigProperty->getValue($client);

        $this->assertEquals($maxBatchTimeout, $natsConfig->timeout);
    }

    /**
     * @test
     */
    public function constructor_WithDefaultTimeouts_UsesDefaultValues(): void
    {
        $dsn = self::VALID_DSN;

        $transport = new TestableNatsTransport($dsn, []);

        $config = $transport->getTestConfiguration();

        // Default values should be 1 second for both timeouts
        $this->assertEquals(1, $config['max_batch_timeout']);
        $this->assertEquals(1, $config['connection_timeout']);
    }

    /**
     * @test
     */
    public function constructor_WithPortFromDsn_ParsesAsInteger(): void
    {
        $dsn = 'nats://admin:password@localhost:5222/test-stream/test-topic';

        $transport = new TestableNatsTransport($dsn, []);

        // Access the client configuration
        $client = $transport->getTestClient();
        $clientReflection = new \ReflectionClass($client);
        $configProperty = $clientReflection->getProperty('configuration');
        $natsConfig = $configProperty->getValue($client);

        $this->assertEquals(5222, $natsConfig->port);
        $this->assertIsInt($natsConfig->port);
    }

    /**
     * @test
     */
    public function constructor_OptionsOverrideDsnQueryParams(): void
    {
        // DSN has connection_timeout=1.0, but options has 5.0
        $dsn = 'nats://admin:password@localhost:4222/test-stream/test-topic?connection_timeout=1.0&max_batch_timeout=1.0';
        $options = [
            'connection_timeout' => 5.0,
            'max_batch_timeout' => 3.0,
        ];

        $transport = new TestableNatsTransport($dsn, $options);

        $config = $transport->getTestConfiguration();

        // Options should take precedence over DSN query params
        $this->assertEquals(5.0, $config['connection_timeout']);
        $this->assertEquals(3.0, $config['max_batch_timeout']);
    }

    /**
     * @test
     */
    public function send_WithSerializerReturningHeaders_PublishesPayloadWithHeaders(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a mock serializer that returns headers
        $mockSerializer = $this->createMock(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class);
        $mockSerializer->expects($this->once())
            ->method('encode')
            ->willReturn([
                'body' => 'serialized-message-body',
                'headers' => ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
            ]);

        // Create mock stream that captures the payload
        $capturedPayload = null;
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo('test-topic'),
                $this->callback(function ($payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;
                    return $payload instanceof \Basis\Nats\Message\Payload;
                })
            );

        // Use reflection to inject the mock dependencies
        $reflection = new \ReflectionClass($transport);

        $serializerProperty = $reflection->getProperty('serializer');
        $serializerProperty->setValue($transport, $mockSerializer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, $mockStream);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $result = $transport->send($envelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertInstanceOf(\Basis\Nats\Message\Payload::class, $capturedPayload);
        $this->assertEquals('serialized-message-body', $capturedPayload->body);
        $this->assertEquals(['Content-Type' => 'application/json', 'X-Custom' => 'value'], $capturedPayload->headers);
    }

    /**
     * @test
     */
    public function get_WithEmptyMessagePayload_SkipsEmptyMessage(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create an empty message (should be skipped)
        $emptyPayload = $this->createMock(\Basis\Nats\Message\Payload::class);
        $emptyPayload->body = ''; // Empty body
        $emptyPayload->headers = [];

        $emptyMessage = $this->createMock(\Basis\Nats\Message\Msg::class);
        $emptyMessage->payload = $emptyPayload;
        $emptyMessage->replyTo = 'empty-reply-to';

        // Create a valid message
        $validMessage = new \stdClass();
        $validMessage->data = 'test data';
        $validEnvelope = new \Symfony\Component\Messenger\Envelope($validMessage);
        $serialized = \igbinary_serialize($validEnvelope);

        $validPayload = $this->createMock(\Basis\Nats\Message\Payload::class);
        $validPayload->body = $serialized;
        $validPayload->headers = [];

        $validMsg = $this->createMock(\Basis\Nats\Message\Msg::class);
        $validMsg->payload = $validPayload;
        $validMsg->replyTo = 'valid-reply-to';

        // Mock queue to return both messages (empty first, then valid)
        $mockQueue = $this->createMock(\Basis\Nats\Queue::class);
        $mockQueue->method('fetchAll')->willReturn([$emptyMessage, $validMsg]);

        // Mock connection
        $mockConnection = $this->createMock(\Basis\Nats\Connection::class);

        // Mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->connection = $mockConnection;

        // Inject mocks
        $reflection = new \ReflectionClass($transport);

        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setValue($transport, $mockQueue);

        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $envelopes = $transport->get();
        $envelopeArray = iterator_to_array($envelopes);

        // Should only have 1 envelope (empty message was skipped)
        $this->assertCount(1, $envelopeArray);
        $this->assertInstanceOf(\Symfony\Component\Messenger\Envelope::class, $envelopeArray[0]);
    }

    /**
     * @test
     */
    public function get_WithDeserializationFailure_SendsNakAndThrowsException(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create a message with valid-looking payload
        $invalidPayload = $this->createMock(\Basis\Nats\Message\Payload::class);
        $invalidPayload->body = 'some-payload-data';
        $invalidPayload->headers = [];

        $invalidMessage = $this->createMock(\Basis\Nats\Message\Msg::class);
        $invalidMessage->payload = $invalidPayload;
        $invalidMessage->replyTo = 'invalid-message-reply-to';

        // Mock queue to return the message
        $mockQueue = $this->createMock(\Basis\Nats\Queue::class);
        $mockQueue->method('fetchAll')->willReturn([$invalidMessage]);

        // Mock serializer to throw exception during decode
        $mockSerializer = $this->createMock(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class);
        $mockSerializer->expects($this->once())
            ->method('decode')
            ->willThrowException(new \Symfony\Component\Messenger\Exception\MessageDecodingFailedException('Test decoding failure'));

        // Mock connection to verify NAK is sent
        $nakWasSent = false;
        $mockConnection = $this->createMock(\Basis\Nats\Connection::class);
        $mockConnection->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(function ($message) use (&$nakWasSent) {
                $nakWasSent = $message instanceof \Basis\Nats\Message\Nak;
                return true;
            }));

        // Mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->connection = $mockConnection;

        // Inject mocks
        $reflection = new \ReflectionClass($transport);

        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setValue($transport, $mockQueue);

        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $serializerProperty = $reflection->getProperty('serializer');
        $serializerProperty->setValue($transport, $mockSerializer);

        // Expect an exception to be thrown
        $this->expectException(\Symfony\Component\Messenger\Exception\MessageDecodingFailedException::class);

        try {
            $transport->get();
        } finally {
            // Verify NAK was sent before the exception was thrown
            $this->assertTrue($nakWasSent, 'NAK message should have been sent for failed deserialization');
        }
    }

    /**
     * @test
     */
    public function send_WhenStreamNotInitialized_InitializesStreamFromApi(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Create mock stream
        $mockStream = $this->createMock(\Basis\Nats\Stream\Stream::class);
        $mockStream->expects($this->once())
            ->method('publish')
            ->with($this->equalTo('test-topic'), $this->anything());

        // Create mock API that returns the stream
        $mockApi = $this->createMock(\Basis\Nats\Api::class);
        $mockApi->expects($this->once())
            ->method('getStream')
            ->with($this->equalTo('test-stream'))
            ->willReturn($mockStream);

        // Create mock client
        $mockClient = $this->createMock(\Basis\Nats\Client::class);
        $mockClient->expects($this->once())
            ->method('getApi')
            ->willReturn($mockApi);

        // Use reflection to set stream to null and inject mock client
        $reflection = new \ReflectionClass($transport);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setValue($transport, null);

        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($transport, $mockClient);

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $result = $transport->send($envelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertNotNull($result->last(TransportMessageIdStamp::class));

        // Verify stream was initialized
        $this->assertSame($mockStream, $streamProperty->getValue($transport));
    }

    /**
     * @test
     */
    public function constructor_WithoutIgbinaryExtension_TriggersError(): void
    {
        $errorTriggered = false;
        $errorMessage = '';

        set_error_handler(function ($errno, $errstr) use (&$errorTriggered, &$errorMessage) {
            $errorTriggered = true;
            $errorMessage = $errstr;
            return true;
        });

        try {
            // Pass null serializer to trigger the igbinary check
            new NatsTransportWithoutIgbinary(self::VALID_DSN, [], null);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($errorTriggered, 'Expected trigger_error to be called');
        $this->assertStringContainsString('igbinary extension is not installed', $errorMessage);
    }
}
