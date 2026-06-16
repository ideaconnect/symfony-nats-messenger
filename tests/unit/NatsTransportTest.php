<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\Future;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\Exception\UnsupportedFeatureException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
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

/**
 * Exercises the real {@see NatsTransport::connect()} (it is NOT overridden here) with an
 * injectable mock client, so the lazy-connect path can be covered without a live broker.
 */
class RealConnectNatsTransport extends NatsTransport
{
    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }
}

final class NatsTransportTest extends TestCase
{
    private const VALID_DSN = 'nats://admin:password@localhost:4222/test-stream/test-topic';

    /**
     * Matches an addStream() StreamConfiguration whose toArray() equals the expected config.
     *
     * @param array<string, mixed> $expected
     */
    private static function streamConfigEquals(array $expected): \PHPUnit\Framework\Constraint\Callback
    {
        return self::callback(static fn (StreamConfiguration $config): bool => $config->toArray() == $expected);
    }

    /**
     * Matches an addConsumer() ConsumerConfiguration whose toArray() equals the expected config.
     *
     * @param array<string, mixed> $expected
     */
    private static function consumerConfigEquals(array $expected): \PHPUnit\Framework\Constraint\Callback
    {
        return self::callback(static fn (ConsumerConfiguration $config): bool => $config->toArray() == $expected);
    }

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
        $this->expectExceptionMessage('No TransportMessageIdStamp found on the Envelope.');

        $transport->ack(new Envelope(new \stdClass()));
    }

    public function testRejectWithoutTransportStampThrowsException(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No TransportMessageIdStamp found on the Envelope.');

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

    public function testSendUsesPublishWithHeadersWhenHeadersArePresent(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->willReturn([
                'body' => 'encoded-payload',
                'headers' => ['x-test' => 123],
            ]);

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->with('test-topic', 'encoded-payload', ['x-test' => '123'])
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
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

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->willReturn(Future::error(new JetStreamException('publish failed', 503)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('publish failed');

        $transport->send(new Envelope(new \stdClass()));
    }

    public function testGetTermsEmptyPayloadMessagesToStopRedelivery(): void
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

        $transport = new RuntimeRetryHandlerNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertCount(1, $envelopes);
        self::assertSame('reply-valid', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
        // The empty-payload message can never decode into an envelope, so it is TERMed (not
        // silently skipped) to stop JetStream redelivering it forever — regardless of retry handler.
        self::assertSame(['term:reply-empty'], $transport->failureActions);
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

    public function testGetSkipsMessagesWithoutReplySubject(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, null, 'payload'),
                new NatsMessage('test-topic', 2, '', 'payload'),
            ]));

        $transport = new RuntimeRetryHandlerNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        // A message without a reply (ack) subject cannot be acked/rejected, so it is skipped
        // before decoding and never triggers retry handling.
        self::assertSame([], $envelopes);
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

    public function testHandleFailedDeliveryUsesNakWithDelayWhenConfigured(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('nakWithDelay')
            ->with(
                self::callback(static function (NatsMessage $message): bool {
                    return $message->replyTo === 'message-id' && $message->subject === 'test-topic';
                }),
                5000
            )
            ->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('nak');

        // nak_delay is in seconds (5s) → 5000ms passed to nakWithDelay().
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats', 'nak_delay' => 5]);
        $transport->setJetStreamContext($jetStream);

        $method = (new \ReflectionClass($transport))->getMethod('handleFailedDelivery');
        $method->invoke($transport, 'message-id');
    }

    public function testSetupAppliesConsumerRetryTuning(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::consumerConfigEquals([
                'durable_name' => 'client',
                'filter_subject' => 'test-topic',
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
                'ack_wait' => 30_000_000_000,
                'max_deliver' => 5,
                'backoff' => [1_000_000_000, 5_000_000_000],
            ]))
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'ack_wait' => 30,
            'max_deliver' => 5,
            'backoff' => [1, 5],
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
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

    public function testAckUsesAckSyncWhenEnabled(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('ackSync')
            ->with(self::callback(static function (NatsMessage $message): bool {
                return $message->replyTo === 'message-id' && $message->subject === 'test-topic';
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('ack');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['ack_sync' => true]);
        $transport->setJetStreamContext($jetStream);

        $transport->ack((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id')));
    }

    public function testConnectInitializesJetStreamContextFromClient(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('ack')
            ->willReturn(Future::complete());

        $client = $this->createMock(NatsClient::class);
        $client->expects(self::once())->method('connect')->willReturn(Future::complete());
        $client->expects(self::once())->method('jetStream')->willReturn($jetStream);

        // RealConnectNatsTransport does not override connect(), so this exercises the lazy
        // connectIfNeeded() -> connect() path that injects the JetStream context from the client.
        $transport = new RealConnectNatsTransport(self::VALID_DSN, []);
        $transport->setClient($client);

        $transport->ack((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id')));
    }

    public function testJetStreamThrowsWhenConnectLeavesContextUnavailable(): void
    {
        // TestableNatsTransport::connect() is a no-op, so the lazy connect leaves the JetStream
        // context null; the guard in jetStream() must surface a clear LogicException.
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('JetStream context is not available.');

        $transport->ack($envelope);
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

        // 2 in-flight (unacked) + 5 waiting = 7 outstanding.
        self::assertSame(7, $transport->getMessageCount());
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
            ->method('addStream')
            ->with(self::streamConfigEquals([
                'storage' => 'file',
                'num_replicas' => 1,
                'name' => 'test-stream',
                'subjects' => ['test-topic'],
            ]))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::consumerConfigEquals([
                'durable_name' => 'client',
                'filter_subject' => 'test-topic',
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
            ]))
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

    }

    public function testSetupPassesConfiguredStreamOptions(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::streamConfigEquals([
                'storage' => 'memory',
                'max_age' => 10_000_000_000,
                'max_bytes' => 1024,
                'max_msgs' => 2048,
                'max_msgs_per_subject' => 128,
                'num_replicas' => 3,
                'name' => 'test-stream',
                'subjects' => ['test-topic'],
            ]))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::consumerConfigEquals([
                'durable_name' => 'worker',
                'filter_subject' => 'test-topic',
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
            ]))
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
            'stream_max_messages_per_subject' => 128,
            'stream_storage' => 'memory',
            'stream_replicas' => 3,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

    }

    public function testSetupUpdatesStreamWhenItAlreadyExists(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['subjects' => ['test-topic'], 'storage' => 'file', 'num_replicas' => 1, 'max_age' => 0, 'max_bytes' => -1, 'max_msgs' => -1, 'max_msgs_per_subject' => -1])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

    }

    public function testSetupPreservesExistingReplicaCountWhenStreamReplicasNotConfigured(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'num_replicas' => 3,
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream name already in use', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['num_replicas'] ?? null) === 3))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        // No stream_replicas option => the existing server replica count (3) must be preserved,
        // not silently reset to the managed default of 1.
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupOverridesExistingReplicaCountWhenStreamReplicasExplicitlyConfigured(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'num_replicas' => 3,
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream name already in use', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['num_replicas'] ?? null) === 5))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        // Explicit stream_replicas=5 must override the server's existing replica count.
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_replicas' => 5]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupDoesNotTreatGenericBadRequestAsExistingStream(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('invalid stream configuration', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::error(new JetStreamException('stream not found', 404)));
        $jetStream->expects(self::never())->method('updateStream');
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream 'test-stream': invalid stream configuration");

        $transport->setup();
    }

    public function testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('bad request', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['subjects' => ['test-topic'], 'storage' => 'file', 'num_replicas' => 1, 'max_age' => 0, 'max_bytes' => -1, 'max_msgs' => -1, 'max_msgs_per_subject' => -1])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

    }

    public function testSetupRejectsUnexpectedConsumerConfiguration(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

    public function testSetupGivesClearErrorWhenScheduledMessagesUnsupported(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new UnsupportedFeatureException(
                'allow_msg_schedules',
                '2.12',
                '2.10.0',
                'unknown field "allow_msg_schedules"',
                400,
            )));
        // A version-feature rejection is not a pre-existing-stream conflict, so no existence check.
        $jetStream->expects(self::never())->method('getStream');
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'scheduled_messages' option requires NATS Server >= 2.12, but the connected server reports 2.10.0");

        $transport->setup();
    }

    public function testSetupWrapsUnsupportedFeatureGenericallyWhenNotScheduledMessages(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new UnsupportedFeatureException(
                'allow_atomic',
                '2.12',
                '2.11.0',
                'unknown field "allow_atomic"',
                400,
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("NATS Server feature 'allow_atomic' requires version >= 2.12, but the connected server reports 2.11.0");

        $transport->setup();
    }

    public function testSetupWrapsUnexpectedStreamCreationErrors(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream failure', 500)));
        // The existence check after a failed create returns 404 (stream absent), so the original
        // creation error is rethrown and wrapped rather than triggering an update.
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::error(new JetStreamException('stream not found', 404)));
        $jetStream->expects(self::never())->method('updateStream');
        $jetStream->expects(self::never())->method('addConsumer');

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
        self::assertSame(E_USER_WARNING, $capturedError[0]);
        self::assertStringContainsString('The igbinary extension is not installed.', $capturedError[1]);
        self::assertStringContainsString('Falling back to Symfony\\Component\\Messenger\\Transport\\Serialization\\PhpSerializer', $capturedError[1]);
        self::assertStringContainsString('untrusted-deserialization', $capturedError[1]);

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

    public function testGetMessageCountSumsAckPendingAndPending(): void
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

        // 10 in-flight (unacked) + 3 waiting = 13 outstanding.
        self::assertSame(13, $transport->getMessageCount());
    }

    public function testSetupUpdatesStreamWhenAlreadyInUseMessage(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream name already in use', 409)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['subjects' => ['test-topic'], 'storage' => 'file', 'num_replicas' => 1, 'max_age' => 0, 'max_bytes' => -1, 'max_msgs' => -1, 'max_msgs_per_subject' => -1])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

    }

    public function testSetupUpdatesStreamWhenAlreadyExistsInMessage(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', ['subjects' => ['test-topic'], 'storage' => 'file', 'num_replicas' => 1, 'max_age' => 0, 'max_bytes' => -1, 'max_msgs' => -1, 'max_msgs_per_subject' => -1])
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('ambiguous bad request', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::error(new JetStreamException('internal server error', 500)));
        $jetStream->expects(self::never())->method('updateStream');
        $jetStream->expects(self::never())->method('addConsumer');

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

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
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
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
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
            ->method('addStream')
            ->with(self::callback(function (StreamConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['storage'] ?? null) === 'file'
                    && ($options['allow_msg_schedules'] ?? false) === true
                    && ($options['num_replicas'] ?? 0) === 1
                    && ($options['subjects'] ?? null) === ['test-topic', 'test-topic.delayed.>'];
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::consumerConfigEquals([
                'durable_name' => 'client',
                'filter_subject' => 'test-topic',
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
            ]))
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

    }

    public function testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'storage' => 'file',
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['subjects'] ?? []) === ['test-topic', 'test-topic.delayed.>']
                    && ($options['allow_msg_schedules'] ?? false) === true
                    && ($options['storage'] ?? null) === 'file';
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

    }

    public function testSetupUpdateClearsAllowMsgSchedulesWhenScheduledMessagesDisabled(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'allow_msg_schedules' => true,
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            // scheduled_messages is off, but the stream previously had allow_msg_schedules=true, so the
            // update must explicitly write false to clear it rather than preserving the server's true.
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['allow_msg_schedules'] ?? null) === false))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdateDoesNotSendAllowMsgSchedulesWhenDisabledAndServerLacksIt(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: ['config' => ['name' => 'test-stream', 'subjects' => ['test-topic']]],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('stream already exists', 400)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            // A server too old for the field never had the key; disabling scheduling must not introduce it.
            ->with('test-stream', self::callback(static fn (array $options): bool => !array_key_exists('allow_msg_schedules', $options)))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['existing-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['existing-topic'],
                    'storage' => 'memory',
                    'discard' => 'old',
                    'retention' => 'limits',
                    'consumer_limits' => [],
                ],
            ],
        );

        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['subjects'] ?? []) === ['existing-topic', 'test-topic']
                    && ($options['storage'] ?? null) === 'memory'
                    && ($options['discard'] ?? null) === 'old'
                    && ($options['retention'] ?? null) === 'limits'
                    && ($options['consumer_limits'] ?? null) instanceof \stdClass;
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_storage' => 'file']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

    }

    public function testSetupUpdatesExistingStreamWithoutDuplicatingSubjects(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'storage' => 'file',
                ],
            ],
        );

        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['subjects'] ?? []) === ['test-topic'];
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

    }

    public function testSetupCreatesNewStreamWithMaxMessages(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::callback(function (StreamConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['max_msgs'] ?? null) === 5000
                    && ($options['storage'] ?? null) === 'file'
                    && ($options['num_replicas'] ?? null) === 1
                    && ($options['subjects'] ?? null) === ['test-topic'];
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_max_messages' => 5000,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

    }

    public function testSetupUpdatesExistingStreamWithMaxMessages(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'storage' => 'file',
                ],
            ],
        );

        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['max_msgs'] ?? null) === 5000
                    && ($options['subjects'] ?? []) === ['test-topic'];
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_max_messages' => 5000,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

    }

    public function testSetupCreatesNewStreamWithMaxMessagesPerSubject(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::callback(function (StreamConfiguration $config): bool {
                $options = $config->toArray();

                return ($options['max_msgs_per_subject'] ?? null) === 100
                    && ($options['storage'] ?? null) === 'file'
                    && ($options['num_replicas'] ?? null) === 1
                    && ($options['subjects'] ?? null) === ['test-topic'];
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_max_messages_per_subject' => 100,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

    }

    public function testSetupUpdatesExistingStreamWithMaxMessagesPerSubject(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'storage' => 'file',
                ],
            ],
        );

        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['max_msgs_per_subject'] ?? null) === 100
                    && ($options['subjects'] ?? []) === ['test-topic'];
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_max_messages_per_subject' => 100,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

    }

    public function testSetupUpdateResetsUnsetStreamLimitsToUnlimited(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'storage' => 'file',
                    // Stream was previously created with limits in place.
                    'max_age' => 3_600_000_000_000,
                    'max_bytes' => 1024,
                    'max_msgs' => 500,
                    'max_msgs_per_subject' => 50,
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(function (array $options): bool {
                return ($options['max_age'] ?? null) === 0
                    && ($options['max_bytes'] ?? null) === -1
                    && ($options['max_msgs'] ?? null) === -1
                    && ($options['max_msgs_per_subject'] ?? null) === -1;
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
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

        // No stream_max_* options provided, so the previously-configured limits must be reset to
        // JetStream's unlimited sentinels on update rather than preserved from the server config.
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdateConvertsConfiguredMaxAgeToNanoseconds(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: ['config' => ['name' => 'test-stream', 'subjects' => ['test-topic'], 'storage' => 'file']],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            // 900 seconds → 900_000_000_000 nanoseconds on the update path.
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['max_age'] ?? null) === 900_000_000_000))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_max_age' => 900]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdateTreatsNonArrayServerSubjectsAsEmpty(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // A malformed server config exposes `subjects` as a non-array; it must be treated as empty so
        // the transport's desired subject is still applied (rather than crashing on the bad value).
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: ['config' => ['name' => 'test-stream', 'subjects' => 'not-an-array', 'storage' => 'file']],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['subjects'] ?? null) === ['test-topic']))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testGetDecodeFailureUsesNakWhenRetryHandlerIsNats(): void
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

        $transport = new RuntimeRetryHandlerNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats'], $serializer);
        $transport->setJetStreamContext($jetStream);

        try {
            iterator_to_array($transport->get());
            self::fail('Expected decode exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('decode failed', $exception->getMessage());
        }

        self::assertSame(['nak:reply-id'], $transport->failureActions);
    }

    public function testGetWithMultipleValidMessagesReturnsAll(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(3))
            ->method('decode')
            ->willReturn(new Envelope(new \stdClass()));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-1', 'payload-1'),
                new NatsMessage('test-topic', 2, 'reply-2', 'payload-2'),
                new NatsMessage('test-topic', 3, 'reply-3', 'payload-3'),
            ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertCount(3, $envelopes);
        self::assertSame('reply-1', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame('reply-2', $envelopes[1]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame('reply-3', $envelopes[2]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testGetWithBatchingConfigPassesBatchSizeToFetchBatch(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->with('test-stream', 'client', 5, self::anything())
            ->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['batching' => 5], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertSame([], $envelopes);
    }

    public function testSetupWrapsConsumerCreationError(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer creation failed', 500)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream 'test-stream': consumer creation failed");

        $transport->setup();
    }

    public function testConstructorWithTlsDsnInitializesTransport(): void
    {
        $transport = new TestableNatsTransport('nats-jetstream+tls://admin:password@localhost:4222/test-stream/test-topic', []);

        self::assertInstanceOf(NatsTransport::class, $transport);
    }

    public function testSendWithNegativeDelayPublishesNormally(): void
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

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(-1000)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    public function testSetupUpdateStreamFailureWrapsException(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->willReturn(Future::error(new JetStreamException('update rejected', 500)));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to setup NATS stream 'test-stream': update rejected");

        $transport->setup();
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

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
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
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(3000)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }
}
