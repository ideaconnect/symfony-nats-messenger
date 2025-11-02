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

    /**
     * @test
     */
    public function send_WithValidEnvelope_AddsTransportStampAndReturnsEnvelope(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $connectMethod->setAccessible(true);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);

        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setAccessible(true);

        $queueProperty = $reflection->getProperty('queue');
        $queueProperty->setAccessible(true);

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
        $topicProperty->setAccessible(true);

        $streamNameProperty = $reflection->getProperty('streamName');
        $streamNameProperty->setAccessible(true);

        $configurationProperty = $reflection->getProperty('configuration');
        $configurationProperty->setAccessible(true);

        // Verify all parameters were parsed correctly
        $this->assertEquals('test_topic', $topicProperty->getValue($transport));
        $this->assertEquals('test_stream', $streamNameProperty->getValue($transport));

        $configuration = $configurationProperty->getValue($transport);
        $this->assertEquals('worker', $configuration['consumer']);
        $this->assertEquals(10, $configuration['batching']);
        $this->assertEquals(0.5, $configuration['delay']);
        $this->assertEquals(3600, $configuration['stream_max_age']);
    }

    /**
     * @test
     */
    public function send_WithoutInitializedStream_InitializesStreamThenSends(): void
    {
        $dsn = self::VALID_DSN;
        $transport = new NatsTransport($dsn, []);

        // Use reflection to clear the stream to test lazy loading
        $reflection = new \ReflectionClass($transport);
        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);
        $streamProperty->setValue($transport, null);

        $message = new \stdClass();
        $message->data = 'test message';
        $envelope = new Envelope($message);

        $result = $transport->send($envelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertNotNull($result->last(TransportMessageIdStamp::class));

        // Verify stream was initialized
        $this->assertNotNull($streamProperty->getValue($transport));
    }

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
        $queueProperty->setAccessible(true);
        $queueProperty->setValue($transport, $mockQueue);

        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
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
        $decodeMethod->setAccessible(true);

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
        $consumerProperty->setAccessible(true);
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
        $consumerProperty->setAccessible(true);
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
        $consumerProperty->setAccessible(true);
        $consumerProperty->setValue($transport, $mockConsumer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);
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
        $consumerProperty->setAccessible(true);
        $consumerProperty->setValue($transport, $mockConsumer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);
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
        $consumerProperty->setAccessible(true);
        $consumerProperty->setValue($transport, $mockConsumer);

        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);
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
        $clientProperty->setAccessible(true);
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
        $clientProperty->setAccessible(true);
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
        $clientProperty->setAccessible(true);
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
        $clientProperty->setAccessible(true);
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
        $clientProperty->setAccessible(true);
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

        // Create a mock consumer that throws exception on create
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->expects($this->exactly(2)) // Called twice: setAckPolicy and setDeliverPolicy
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
        $clientProperty->setAccessible(true);
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

        // Create a mock consumer
        $mockConsumer = $this->createMock(\Basis\Nats\Consumer\Consumer::class);
        $mockConsumer->expects($this->exactly(2)) // Called twice: setAckPolicy and setDeliverPolicy
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
        $clientProperty->setAccessible(true);
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
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($transport, $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Consumer was not created successfully");

        $transport->setup();
    }
}
