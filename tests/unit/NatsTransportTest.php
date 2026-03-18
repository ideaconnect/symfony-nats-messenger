<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\Future;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NatsMessenger\NatsTransport;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class TestableNatsTransport extends NatsTransport
{
    protected function connect(): void
    {
        // Keep unit tests isolated from live NATS.
    }
}

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

class TestableRetryHandlerNatsTransport extends TestableNatsTransport
{
    /** @var list<string> */
    public array $failureActions = [];

    protected function sendNak(string $id): void
    {
        $this->failureActions[] = 'nak:' . $id;
    }

    protected function sendTerm(string $id): void
    {
        $this->failureActions[] = 'term:' . $id;
    }
}

class RuntimeTestableNatsTransport extends TestableNatsTransport
{
    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }

    public function setJetStreamContext(JetStreamContext $jetStream): void
    {
        $this->jetStream = $jetStream;
    }
}

class RuntimeRetryHandlerNatsTransport extends TestableRetryHandlerNatsTransport
{
    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }

    public function setJetStreamContext(JetStreamContext $jetStream): void
    {
        $this->jetStream = $jetStream;
    }
}

final class NatsTransportTest extends TestCase
{
    private const VALID_DSN = 'nats://admin:password@localhost:4222/test-stream/test-topic';

    public function testConstructorWithValidDsnInitializesTransport(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);

        self::assertInstanceOf(NatsTransport::class, $transport);
        self::assertInstanceOf(TransportInterface::class, $transport);
        self::assertInstanceOf(SetupableTransportInterface::class, $transport);
    }

    public function testConstructorWithDottedTopicInitializesTransport(): void
    {
        $transport = new TestableNatsTransport('nats://admin:password@localhost:4222/test-stream/test.messages', []);

        self::assertInstanceOf(NatsTransport::class, $transport);
    }

    public function testConstructorWithInvalidDsnThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given NATS DSN is invalid');

        new TestableNatsTransport('not-a-valid-dsn', []);
    }

    public function testConstructorWithoutPathThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS Stream name not provided');

        new TestableNatsTransport('nats://localhost:4222', []);
    }

    public function testConstructorWithoutTopicThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both stream name and topic name');

        new TestableNatsTransport('nats://localhost:4222/stream-only/', []);
    }

    public function testConstructorWithWildcardTopicThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only dot-separated subject tokens containing alphanumeric characters, hyphens, and underscores are allowed.');

        new TestableNatsTransport('nats://localhost:4222/test-stream/test.*', []);
    }

    public function testFindReceivedStampReturnsTransportStamp(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('stamp-id'));

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('findReceivedStamp');

        $result = $method->invoke($transport, $envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result);
        self::assertSame('stamp-id', $result->getId());
    }

    public function testAckWithoutTransportStampThrowsException(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No ReceivedStamp found on the Envelope.');

        $transport->ack(new Envelope(new \stdClass()));
    }

    public function testRejectWithoutTransportStampThrowsException(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No ReceivedStamp found on the Envelope.');

        $transport->reject(new Envelope(new \stdClass()));
    }

    public function testRejectUsesTermByDefault(): void
    {
        $transport = new TestableRetryHandlerNatsTransport(self::VALID_DSN, []);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id'));

        $transport->reject($envelope);

        self::assertSame(['term:message-id'], $transport->failureActions);
    }

    public function testRejectUsesNakWhenRetryHandlerIsNats(): void
    {
        $transport = new TestableRetryHandlerNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats']);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id'));

        $transport->reject($envelope);

        self::assertSame(['nak:message-id'], $transport->failureActions);
    }

    public function testHandleFailedDeliveryUsesTermByDefault(): void
    {
        $transport = new TestableRetryHandlerNatsTransport(self::VALID_DSN, []);

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleFailedDelivery');
        $method->invoke($transport, 'decode-failure-id');

        self::assertSame(['term:decode-failure-id'], $transport->failureActions);
    }

    public function testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats(): void
    {
        $transport = new TestableRetryHandlerNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats']);

        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('handleFailedDelivery');
        $method->invoke($transport, 'decode-failure-id');

        self::assertSame(['nak:decode-failure-id'], $transport->failureActions);
    }

    public function testSendSerializationFailureUsesErrorDetailsStampMessage(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);

        $message = new \stdClass();
        $message->closure = static fn (): string => 'cannot-serialize';
        $envelope = new Envelope(
            $message,
            [new ErrorDetailsStamp(\RuntimeException::class, 500, 'Custom serialization message')]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Custom serialization message');

        $transport->send($envelope);
    }

    public function testSendPublishesEncodedBodyWithoutHeaders(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->willReturn(['body' => 'encoded-payload']);

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->with('test-topic', 'encoded-payload')
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $result = $transport->send(new Envelope(new \stdClass()));

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    public function testGetReturnsDecodedEnvelopeWithHeadersAndMessageId(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->with([
                'body' => 'payload',
                'headers' => ['foo' => 'bar'],
            ])
            ->willReturn(new Envelope(new \stdClass()));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->with('test-stream', 'client', 1, 1000)
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-id', 'payload', NatsHeaders::toWireBlock(['foo' => 'bar'])),
            ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertCount(1, $envelopes);
        self::assertSame('reply-id', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testSendUsesRequestWithHeadersWhenHeadersArePresent(): void
    {
<<<<<<< HEAD
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
=======
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
>>>>>>> zlatko/support-delays
            ->method('encode')
            ->willReturn([
                'body' => 'encoded-payload',
                'headers' => ['x-test' => 123],
            ]);

        $client = $this->createMock(NatsClient::class);
        $client->expects(self::once())
            ->method('requestWithHeaders')
            ->with('test-topic', 'encoded-payload', ['x-test' => '123'])
            ->willReturn(Future::complete(new NatsMessage('test-topic', 1, null, '{}')));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);

        $result = $transport->send(new Envelope(new \stdClass()));

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    public function testSendThrowsWhenJetStreamHeaderPublishReturnsError(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->willReturn([
                'body' => 'encoded-payload',
                'headers' => ['x-test' => '123'],
            ]);

        $client = $this->createMock(NatsClient::class);
        $client->expects(self::once())
            ->method('requestWithHeaders')
            ->willReturn(Future::complete(new NatsMessage('test-topic', 1, null, '{"error":{"description":"publish failed","code":503}}')));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setClient($client);
        $transport->setJetStreamContext($this->createMock(JetStreamContext::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish failed');

        $transport->send(new Envelope(new \stdClass()));
    }

    public function testGetSkipsEmptyPayloadMessages(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->willReturn(new Envelope(new \stdClass()));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-empty', ''),
                new NatsMessage('test-topic', 2, 'reply-valid', 'payload'),
            ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertCount(1, $envelopes);
        self::assertSame('reply-valid', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testGetReturnsEmptyArrayWhenConsumerIsMissing(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('missing consumer', 404)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    public function testGetReturnsEmptyArrayWhenBatchRequestTimesOut(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('batch timeout', 408)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    public function testGetRethrowsUnexpectedJetStreamExceptions(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('unexpected failure', 500)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('unexpected failure');

        iterator_to_array($transport->get());
    }

    public function testGetDecodeFailureUsesTermWhenReplySubjectExists(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->willThrowException(new \RuntimeException('decode failed'));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-id', 'payload'),
            ]));

        $transport = new RuntimeRetryHandlerNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        try {
            iterator_to_array($transport->get());
            self::fail('Expected decode exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('decode failed', $exception->getMessage());
        }

        self::assertSame(['term:reply-id'], $transport->failureActions);
    }

    public function testGetDecodeFailureDoesNotInvokeRetryHandlingWithoutReplySubject(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->willThrowException(new \RuntimeException('decode failed'));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, null, 'payload'),
            ]));

        $transport = new RuntimeRetryHandlerNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        try {
            iterator_to_array($transport->get());
            self::fail('Expected decode exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('decode failed', $exception->getMessage());
        }

        self::assertSame([], $transport->failureActions);
    }

    public function testHandleFailedDeliveryUsesBaseTermTransportPath(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('term')
            ->with(self::callback(static function (NatsMessage $message): bool {
                return $message->replyTo === 'message-id' && $message->subject === 'test-topic';
            }))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $method = (new \ReflectionClass($transport))->getMethod('handleFailedDelivery');
        $method->invoke($transport, 'message-id');
    }

    public function testHandleFailedDeliveryUsesBaseNakTransportPath(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('nak')
            ->with(self::callback(static function (NatsMessage $message): bool {
                return $message->replyTo === 'message-id' && $message->subject === 'test-topic';
            }))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats']);
        $transport->setJetStreamContext($jetStream);

        $method = (new \ReflectionClass($transport))->getMethod('handleFailedDelivery');
        $method->invoke($transport, 'message-id');
    }

    public function testAckAcknowledgesReceivedEnvelope(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('ack')
            ->with(self::callback(static function (NatsMessage $message): bool {
                return $message->replyTo === 'message-id' && $message->subject === 'test-topic';
            }))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->ack((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id')));
    }

    public function testGetMessageCountReturnsConsumerPendingMessages(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->with('test-stream', 'client')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'num_ack_pending' => 2,
                    'num_pending' => 5,
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(5, $transport->getMessageCount());
    }

    public function testGetMessageCountFallsBackToStreamState(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new \RuntimeException('consumer lookup failed')));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete(new StreamInfo(
                name: 'test-stream',
                subjects: ['test-topic'],
                raw: [
                    'state' => ['messages' => 7],
                    'config' => ['name' => 'test-stream', 'subjects' => ['test-topic']],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(7, $transport->getMessageCount());
    }

    public function testGetMessageCountReturnsZeroWhenLookupsFail(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new \RuntimeException('consumer lookup failed')));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::error(new \RuntimeException('stream lookup failed')));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(0, $transport->getMessageCount());
    }

    public function testSetupCreatesStreamAndConsumer(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->with('test-stream', ['test-topic'], ['num_replicas' => 1])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->with('test-stream', 'client', 'test-topic', [
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
            ])
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSetupPassesConfiguredStreamOptions(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->with('test-stream', ['test-topic'], [
                'max_age' => 10_000_000_000,
                'max_bytes' => 1024,
                'max_msgs' => 2048,
                'num_replicas' => 3,
            ])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->with('test-stream', 'worker', 'test-topic', [
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
            ])
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'worker',
                push: false,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'consumer' => 'worker',
            'stream_max_age' => 10,
            'stream_max_bytes' => 1024,
            'stream_max_messages' => 2048,
            'stream_replicas' => 3,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSetupUpdatesStreamWhenItAlreadyExists(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['num_replicas' => 1, 'subjects' => ['test-topic']])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSetupDoesNotTreatGenericBadRequestAsExistingStream(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('invalid stream configuration', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::error(new JetStreamException('stream not found', 404)));
        $jetStream->expects(self::never())->method('updateStream');
        $jetStream->expects(self::never())->method('createConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream 'test-stream': invalid stream configuration");

        $transport->setup();
    }

    public function testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('bad request', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete(new StreamInfo(
                name: 'test-stream',
                subjects: ['test-topic'],
                raw: [
                    'config' => [
                        'name' => 'test-stream',
                        'subjects' => ['test-topic'],
                    ],
                ],
            )));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['num_replicas' => 1, 'subjects' => ['test-topic']])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSetupRejectsUnexpectedConsumerConfiguration(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: true,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                        'deliver_subject' => 'push-subject',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer must be configured as a pull consumer.');

        $transport->setup();
    }

    public function testSetupWrapsUnexpectedStreamCreationErrors(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('stream failure', 500)));
        $jetStream->expects(self::never())->method('createConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream 'test-stream': stream failure");

        $transport->setup();
    }

    public function testConstructorWithoutIgbinaryDoesNotCrash(): void
    {
        $capturedError = null;

        set_error_handler(static function (int $severity, string $message) use (&$capturedError): bool {
            $capturedError = [$severity, $message];

            return true;
        });

        try {
            $transport = new NatsTransportWithoutIgbinary(self::VALID_DSN, []);
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($capturedError);
        self::assertSame(E_USER_NOTICE, $capturedError[0]);
        self::assertStringContainsString('The igbinary extension is not installed.', $capturedError[1]);
        self::assertStringContainsString('Falling back to Symfony\\Component\\Messenger\\Transport\\Serialization\\PhpSerializer.', $capturedError[1]);

        self::assertInstanceOf(NatsTransport::class, $transport);

        $reflection = new \ReflectionClass($transport);
        $serializerProperty = $reflection->getProperty('serializer');

        self::assertInstanceOf(PhpSerializer::class, $serializerProperty->getValue($transport));
    }

    public function testConstructorWithInvalidRetryHandlerThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid retry_handler option 'invalid'. Allowed values are 'symfony' or 'nats'.");

        new TestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'invalid']);
    }

    public function testAssertJetStreamPublishSucceededThrowsWhenErrorIsString(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertJetStreamPublishSucceeded');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish failed');

        $method->invoke($transport, '{"error":"publish failed","code":503}');
    }

    public function testAssertJetStreamPublishSucceededThrowsWhenErrorPayloadIsMalformed(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertJetStreamPublishSucceeded');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JetStream publish error');

        $method->invoke($transport, '{"error":true}');
    }

    public function testAssertConsumerMatchesConfigurationRejectsUnexpectedConfig(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertConsumerMatchesConfiguration');
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: [
                'config' => [
                    'ack_policy' => 'none',
                    'deliver_policy' => 'all',
                    'filter_subject' => 'test-topic',
                ],
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer ack policy must be explicit.');

        $method->invoke($transport, $consumerInfo);
    }

    public function testGetMessageCountReturnsAckPendingWhenHigherThanPending(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->with('test-stream', 'client')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'num_ack_pending' => 10,
                    'num_pending' => 3,
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(10, $transport->getMessageCount());
    }

    public function testSetupUpdatesStreamWhenAlreadyInUseMessage(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('stream name already in use', 409)));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['num_replicas' => 1, 'subjects' => ['test-topic']])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSetupUpdatesStreamWhenAlreadyExistsInMessage(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['num_replicas' => 1, 'subjects' => ['test-topic']])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('createConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [
                    'config' => [
                        'ack_policy' => 'explicit',
                        'deliver_policy' => 'all',
                        'filter_subject' => 'test-topic',
                    ],
                ],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testAssertJetStreamPublishSucceededPassesOnValidAck(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertJetStreamPublishSucceeded');

        $method->invoke($transport, '{"stream":"s","seq":1}');

        self::assertTrue(true);
    }

    public function testAssertJetStreamPublishSucceededPassesOnEmptyPayload(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertJetStreamPublishSucceeded');

        $method->invoke($transport, '');

        self::assertTrue(true);
    }

    public function testAssertJetStreamPublishSucceededThrowsOnInvalidJson(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertJetStreamPublishSucceeded');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected JetStream publish response.');

        $method->invoke($transport, 'not-json');
    }

    public function testAssertConsumerMatchesConfigurationRejectsWrongDeliverPolicy(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertConsumerMatchesConfiguration');
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: [
                'config' => [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'new',
                    'filter_subject' => 'test-topic',
                ],
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer deliver policy must be all.');

        $method->invoke($transport, $consumerInfo);
    }

    public function testAssertConsumerMatchesConfigurationRejectsWrongFilterSubject(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertConsumerMatchesConfiguration');
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: [
                'config' => [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                    'filter_subject' => 'wrong-topic',
                ],
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer filter subject does not match the configured topic.');

        $method->invoke($transport, $consumerInfo);
    }

    public function testAssertConsumerMatchesConfigurationRejectsWrongStreamOrConsumerName(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertConsumerMatchesConfiguration');
        $consumerInfo = new ConsumerInfo(
            streamName: 'wrong-stream',
            name: 'client',
            push: false,
            raw: [
                'config' => [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                    'filter_subject' => 'test-topic',
                ],
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Consumer was not created successfully.');

        $method->invoke($transport, $consumerInfo);
    }

    public function testSendSerializationFailureRethrowsOriginalExceptionWithoutErrorDetailsStamp(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->willThrowException(new \RuntimeException('serialize-boom'));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $jetStream = $this->createMock(JetStreamContext::class);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('serialize-boom');

        $transport->send($envelope);
    }

    public function testSetupRethrowsNon404JetStreamExceptionFromStreamExistsCheck(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('ambiguous bad request', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::error(new JetStreamException('internal server error', 500)));
        $jetStream->expects(self::never())->method('updateStream');
        $jetStream->expects(self::never())->method('createConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('internal server error');

        $transport->setup();
    }
}
