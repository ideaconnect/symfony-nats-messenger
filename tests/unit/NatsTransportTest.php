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
use Symfony\Component\Messenger\Stamp\DelayStamp;
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
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
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

    public function testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->willReturn(['body' => 'encoded-payload']);

        $client = $this->createMock(NatsClient::class);
        $client->expects(self::once())
            ->method('requestWithHeaders')
            ->with(
                self::matchesRegularExpression('/^test-topic\.delayed\.[0-9a-f-]{36}$/'),
                'encoded-payload',
                self::callback(function (array $headers): bool {
                    return isset($headers['Nats-Schedule'])
                        && str_starts_with($headers['Nats-Schedule'], '@at ')
                        && isset($headers['Nats-Schedule-Target'])
                        && $headers['Nats-Schedule-Target'] === 'test-topic';
                })
            )
            ->willReturn(Future::complete(new NatsMessage('test-topic', 1, null, '{}')));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(5000)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    public function testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally(): void
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

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(5000)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    public function testSendWithZeroDelayPublishesNormally(): void
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(0)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    public function testSetupWithScheduledMessagesAddsDelayedSubjectAndFlag(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->with('test-stream', ['test-topic', 'test-topic.delayed.>'], self::callback(function (array $options): bool {
                return ($options['allow_msg_schedules'] ?? false) === true
                    && ($options['num_replicas'] ?? 0) === 1;
            }))
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('createStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['subjects'] ?? []) === ['test-topic', 'test-topic.delayed.>']
                    && ($options['allow_msg_schedules'] ?? false) === true;
            }))
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertTrue(true);
    }

    public function testSendWithDelayStampAndExistingHeadersMergesScheduleHeaders(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->willReturn([
                'body' => 'encoded-payload',
                'headers' => ['x-custom' => 'value'],
            ]);

        $client = $this->createMock(NatsClient::class);
        $client->expects(self::once())
            ->method('requestWithHeaders')
            ->with(
                self::matchesRegularExpression('/^test-topic\.delayed\.[0-9a-f-]{36}$/'),
                'encoded-payload',
                self::callback(function (array $headers): bool {
                    return $headers['x-custom'] === 'value'
                        && isset($headers['Nats-Schedule'])
                        && str_starts_with($headers['Nats-Schedule'], '@at ')
                        && $headers['Nats-Schedule-Target'] === 'test-topic';
                })
            )
            ->willReturn(Future::complete(new NatsMessage('test-topic', 1, null, '{}')));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(3000)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }
}
