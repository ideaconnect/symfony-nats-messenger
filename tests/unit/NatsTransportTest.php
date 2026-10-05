<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\DeferredFuture;
use Amp\Future;
use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\ConnectionException;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\Exception\UnsupportedFeatureException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Messenger\Envelope;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Worker;
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
 * Rejects failed deliveries by throwing, so a test can prove the original decode error survives a
 * secondary NAK/TERM transport failure instead of being masked by it.
 */
class ThrowingFailureNatsTransport extends TestableNatsTransport
{
    public function setJetStreamContext(JetStreamContext $jetStream): void
    {
        $this->jetStream = $jetStream;
    }

    protected function sendTerm(string $id): void
    {
        throw new \RuntimeException('term transport failure');
    }

    protected function sendNak(string $id): void
    {
        throw new \RuntimeException('nak transport failure');
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

/**
 * The real connect() (as {@see RealConnectNatsTransport}) with a clock the test moves, for the check of a
 * connection that sat idle.
 */
class ClockedNatsTransport extends RealConnectNatsTransport
{
    public float $now = 1000.0;

    protected function monotonicSeconds(): float
    {
        return $this->now;
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

    /**
     * The exception that carries the ErrorDetailsStamp's message keeps the serializer's error as its previous
     * exception, and has no code of its own.
     */
    public function testSendSerializationFailureKeepsTheSerializerErrorAsThePreviousException(): void
    {
        $serializerError = new \RuntimeException('cannot serialize', 42);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willThrowException($serializerError);
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);
        $envelope = new Envelope(new \stdClass(), [new ErrorDetailsStamp(\RuntimeException::class, 500, 'Custom serialization message')]);

        try {
            $transport->send($envelope);
            self::fail('send() was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Custom serialization message', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertSame($serializerError, $exception->getPrevious());
        }
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
            ->with('test-topic', 'encoded-payload', [])
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

    public function testGetPassesEmptyHeadersToDecodeWhenNoWireHeaders(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        // A message with no wire headers must decode with an explicit empty headers array, exercising
        // the `[]` arm of get()'s rawHeaders ternary.
        $serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => 'payload', 'headers' => []])
            ->willReturn(new Envelope(new \stdClass()));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-id', 'payload'),
            ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        self::assertCount(1, array_values(iterator_to_array($transport->get())));
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
        // silently skipped) to stop JetStream redelivering it forever - regardless of retry handler.
        self::assertSame(['term:reply-empty'], $transport->failureActions);
    }

    /**
     * A 404 reports a pull that found no messages. A missing consumer does not report 404 but 503, which
     * propagates without auto_setup ({@see testGetStillPropagates503WhenAutoSetupIsDisabled()}).
     */
    public function testGetReturnsEmptyArrayWhenThePullReports404(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('No Messages', 404)));

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

    public function testGetDecodeFailureKeepsOriginalErrorWhenRejectAlsoFails(): void
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

        $transport = new ThrowingFailureNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        // The reject (TERM) transport call throws, but the decode error is the root cause an operator
        // needs, so it - not the secondary acknowledgement failure - must be the exception that escapes.
        try {
            iterator_to_array($transport->get());
            self::fail('Expected the original decode exception to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('decode failed', $exception->getMessage());
        }
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

    /**
     * Skipping a message without a reply subject does not end the batch: the messages after it are delivered.
     */
    public function testGetDeliversTheRestOfTheBatchAfterAMessageWithoutAReplySubject(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('decode')->willReturn(new Envelope(new \stdClass()));

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, null, 'payload'),
                new NatsMessage('test-topic', 2, 'reply-valid', 'payload'),
            ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertCount(1, $envelopes);
        self::assertSame('reply-valid', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    public function testGetDecodesLargePayloadWithoutTruncation(): void
    {
        // 1 MiB payload - the full body must reach the serializer intact on the receive path.
        $largePayload = str_repeat('B', 1024 * 1024);
        $decodedEnvelope = new Envelope(new \stdClass());

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('decode')->willReturnCallback(
            function (array $encoded) use ($decodedEnvelope, $largePayload): Envelope {
                self::assertSame($largePayload, $encoded['body']);

                return $decodedEnvelope;
            }
        );

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-id', $largePayload),
            ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertCount(1, $envelopes);
    }

    public function testGetUsesConfiguredConsumerNameSoWorkersShareOneDurableConsumer(): void
    {
        // Multiple workers configured with the same `consumer` name must all pull from that one durable
        // pull consumer - this is what lets JetStream load-balance a batch across them. Assert the
        // configured name (not the default 'client') is the one passed to fetchBatch.
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->with('test-stream', 'shared-workers', self::anything(), self::anything())
            ->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['consumer' => 'shared-workers'], $serializer);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
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

    public function testKeepaliveSendsInProgressForReplyToken(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('inProgress')
            ->with(self::callback(static function (NatsMessage $message): bool {
                return $message->replyTo === 'message-id' && $message->subject === 'test-topic';
            }))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->keepalive((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id')));
    }

    /**
     * Symfony's `messenger:consume --keepalive` calls keepalive() from its SIGALRM handler, where PHP does not
     * allow switching fibers. Waiting for the acknowledgement there failed with "Cannot switch fibers in
     * current execution context" and stopped the worker at the first alarm (#48); it is queued instead.
     */
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting(): void
    {
        $acknowledged = new DeferredFuture();
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('inProgress')->willReturn($acknowledged->getFuture());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));

        $failure = null;
        $asyncSignals = pcntl_async_signals(false);
        pcntl_signal(SIGALRM, static function () use ($transport, $envelope, &$failure): void {
            try {
                $transport->keepalive($envelope);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });
        try {
            posix_kill(posix_getpid(), SIGALRM);
            pcntl_signal_dispatch();
        } finally {
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($asyncSignals);
        }

        self::assertNull($failure, 'keepalive() failed inside the signal handler: ' . $failure?->getMessage());
        $acknowledged->complete();
    }

    /**
     * Outside a signal handler too, keepalive() returns without waiting for the server.
     */
    public function testKeepaliveDoesNotWaitForTheAcknowledgement(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('inProgress')->willReturn((new DeferredFuture())->getFuture());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->keepalive((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token')));
    }

    /**
     * A transport without a connection sends nothing rather than dialling, which would mean waiting.
     */
    public function testKeepaliveWithoutAConnectionSendsNothingAndDoesNotDial(): void
    {
        $client = $this->createMock(NatsClient::class);
        $client->expects(self::never())->method('connect');

        $transport = new RealConnectNatsTransport(self::VALID_DSN, []);
        $transport->setClient($client);

        $transport->keepalive((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token')));
    }

    /**
     * An acknowledgement that fails - the connection is gone - stays on its own fiber: nothing reaches the
     * event loop as an unhandled error, which would stop the worker.
     */
    public function testKeepaliveWhoseAcknowledgementFailsRaisesNoUnhandledError(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // A new future per call, which only the transport holds: left unhandled, it would be reported as soon
        // as the transport lets go of it, so within this test.
        $jetStream->expects(self::once())->method('inProgress')->willReturnCallback(
            static fn (): Future => Future::error(new ConnectionException('Connection is not open')),
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->keepalive((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token')));
        // An unhandled failure would surface from the event loop here.
        \Amp\delay(0);
    }

    public function testKeepaliveWithoutTransportStampThrowsException(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No TransportMessageIdStamp found on the Envelope.');

        $transport->keepalive(new Envelope(new \stdClass()));
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

    public function testAckPropagatesJetStreamErrorFromAckSync(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // ack_sync exists precisely to surface a dropped/failed acknowledgement; the error must not be
        // swallowed, or the worker would believe a redelivered message was successfully acknowledged.
        $jetStream->expects(self::once())
            ->method('ackSync')
            ->willReturn(Future::error(new JetStreamException('ack timeout', 408)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['ack_sync' => true]);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('ack timeout');

        $transport->ack((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id')));
    }

    public function testConnectIsIdempotentAcrossOperations(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('ack')->willReturn(Future::complete());

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willReturn(['body' => 'encoded-payload']);

        $client = $this->createMock(NatsClient::class);
        // Two transport operations must open exactly one connection (lazy connect is cached); a
        // regression that reconnected per operation would open a socket on every send/ack.
        $client->expects(self::once())->method('connect')->willReturn(Future::complete());
        $client->expects(self::once())->method('jetStream')->willReturn($jetStream);
        // The transport asks the client whether its connection is still open before reusing it.
        $client->method('state')->willReturn(ConnectionState::Open);

        $transport = new RealConnectNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setClient($client);

        $transport->send(new Envelope(new \stdClass()));
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

    public function testCloseDisconnectsTheClientAndReconnectsOnNextOperation(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $client = $this->createMock(NatsClient::class);
        // Two connects (the second proves close() reset the lazy state so the transport reconnects),
        // and exactly one disconnect from close().
        $client->expects(self::exactly(2))->method('connect')->willReturn(Future::complete());
        $client->expects(self::exactly(2))->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new RealConnectNatsTransport(self::VALID_DSN, []);
        $transport->setClient($client);

        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('message-id'));
        $transport->ack($envelope);
        $transport->close();
        $transport->ack($envelope);
    }

    public function testCloseIsNoOpWhenNeverConnected(): void
    {
        $client = $this->createMock(NatsClient::class);
        $client->expects(self::never())->method('disconnect');

        $transport = new RealConnectNatsTransport(self::VALID_DSN, []);
        $transport->setClient($client);

        $transport->close();
    }

    /**
     * Once the client has closed - the server closed the connection or it dropped (the client runs with
     * reconnect off), or credentials were refused - every operation dials again instead of failing on the
     * Closed client until the process restarts (#49), and runs on the new connection.
     *
     * @param \Closure(NatsTransport): void $operation
     */
    #[DataProvider('operationsAfterTheClientClosed')]
    public function testOperationAfterTheClientClosedDialsAgain(string $method, mixed $result, \Closure $operation): void
    {
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::once())->method('ack')->willReturn(Future::complete());
        $oldConnection->expects(self::never())->method($method === 'ack' ? 'nak' : $method);
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::once())->method($method)->willReturn(Future::complete($result));

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->expects(self::exactly(2))->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        // A Closed client has already released its socket: there is nothing to disconnect.
        $client->expects(self::never())->method('disconnect');

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new RealConnectNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setClient($client);
        $transport->ack((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('first')));

        $state = ConnectionState::Closed;
        $operation($transport);
    }

    /**
     * @return iterable<string, array{string, mixed, \Closure(NatsTransport): void}>
     */
    public static function operationsAfterTheClientClosed(): iterable
    {
        $received = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));

        yield 'send' => ['publish', null, static fn (NatsTransport $transport) => $transport->send(new Envelope(new \stdClass()))];
        yield 'get' => ['fetchBatch', [], static fn (NatsTransport $transport) => iterator_to_array($transport->get())];
        yield 'ack' => ['ack', null, static fn (NatsTransport $transport) => $transport->ack($received)];
        yield 'reject' => ['term', null, static fn (NatsTransport $transport) => $transport->reject($received)];
        yield 'getMessageCount' => [
            'getConsumer',
            new ConsumerInfo(streamName: 'test-stream', name: 'client', push: false, raw: ['num_pending' => 3, 'num_ack_pending' => 0]),
            static fn (NatsTransport $transport) => self::assertSame(3, $transport->getMessageCount()),
        ];
    }

    /**
     * With auto_setup the new connection is verified in the same call that dialled it, before the publish:
     * a stream removed during the outage is recreated before the message is sent to it, not one call late.
     */
    public function testAutoSetupVerifiesTheNewConnectionBeforeTheSameCallPublishes(): void
    {
        $calls = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturnCallback(static function () use (&$calls): Future {
            $calls[] = 'addStream';

            return Future::complete();
        });
        $jetStream->method('addConsumer')->willReturnCallback(static function () use (&$calls): Future {
            $calls[] = 'addConsumer';

            return Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            ));
        });
        $jetStream->method('publish')->willReturnCallback(static function () use (&$calls): Future {
            $calls[] = 'publish';

            return Future::complete();
        });

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()], $calls);
        $client->method('jetStream')->willReturn($jetStream);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['auto_setup' => true], $serializer);
        $transport->setClient($client);

        $transport->send(new Envelope(new \stdClass()));
        $state = ConnectionState::Closed;
        $calls = [];
        $transport->send(new Envelope(new \stdClass()));

        self::assertSame(['connect', 'addStream', 'addConsumer', 'publish'], $calls);
    }

    /**
     * A dial that fails after the client closed surfaces as the connection error it is - with auto_setup as
     * well, where it used to be wrapped as "Failed to setup NATS stream" - and the next operation dials
     * again.
     */
    public function testFailedDialAfterTheClientClosedSurfacesAsTheConnectionErrorAndIsRetried(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));
        $jetStream->expects(self::exactly(2))->method('publish')->willReturn(Future::complete());

        $refused = new ConnectionException('Connection to tcp://localhost:4222 failed');
        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::error($refused), Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['auto_setup' => true], $serializer);
        $transport->setClient($client);
        $transport->send(new Envelope(new \stdClass()));
        $state = ConnectionState::Closed;

        try {
            $transport->send(new Envelope(new \stdClass()));
            self::fail('expected the dial to fail');
        } catch (ConnectionException $e) {
            self::assertSame($refused, $e);
        }

        // The failed dial left the client closed: the next operation dials again.
        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * A connection that went unused for longer than ping_after_idle is checked with one PING before the next
     * operation; answered, it is used as it is.
     */
    public function testIdleConnectionIsCheckedWithAPingAndKeptWhenTheServerAnswers(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));
        $client->expects(self::never())->method('disconnect');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 31;
        $transport->ack($envelope);
    }

    /**
     * A server drops a client that stops answering its pings - a PHP process between requests does - with
     * -ERR 'Stale Connection', and the client only finds out when it next uses the connection. An idle
     * connection that does not answer the PING is given up and the operation runs on a new one, instead of
     * being the operation that fails (#49).
     */
    public function testIdleConnectionThatDoesNotAnswerThePingIsReplacedBeforeTheOperation(): void
    {
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::once())->method('ack')->willReturn(Future::complete());
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::once())->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->expects(self::exactly(2))->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->expects(self::once())->method('rtt')->willReturn(Future::error(
            new ConnectionException("Server sent error frame: 'Stale Connection'"),
        ));
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 31;
        $transport->ack($envelope);
    }

    /**
     * Closing the connection that failed the PING may fail as well - its socket is already dead - and that
     * must not stop the transport from moving to a new connection.
     */
    public function testFailedCloseOfTheIdleConnectionDoesNotStopItsReplacement(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->method('rtt')->willReturn(Future::error(new ConnectionException('Connection lost')));
        $client->expects(self::once())->method('disconnect')->willReturn(Future::error(new \RuntimeException('Broken pipe')));

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 31;
        $transport->ack($envelope);
    }

    /**
     * A PING still unanswered after connection_timeout counts as no answer: a server that cannot answer in the
     * time a fresh dial may take is treated as gone, rather than holding the operation up.
     */
    public function testPingUnansweredWithinTheConnectionTimeoutReplacesTheConnection(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn((new DeferredFuture())->getFuture());
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30, 'connection_timeout' => 0.1]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 31;
        // A real socket's watcher keeps the event loop alive while the PING is outstanding; without one the
        // loop would run out of work and end the wait by itself.
        $socketWatcher = EventLoop::delay(5.0, static function (): void {});
        $start = hrtime(true);
        try {
            $transport->ack($envelope);
        } finally {
            EventLoop::cancel($socketWatcher);
        }

        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'gave up the PING at connection_timeout');
    }

    /**
     * A connection used within ping_after_idle is not checked: the PING costs a round trip, which only a
     * connection that went quiet for a while needs.
     */
    public function testRecentlyUsedConnectionIsNotPinged(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(3))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);
        $transport->now += 20;
        $transport->ack($envelope);
        // Measured from the last use, not from the first.
        $transport->now += 20;
        $transport->ack($envelope);
    }

    /**
     * Only a connection unused for longer than ping_after_idle is checked; one last used exactly that long ago
     * is not.
     */
    public function testConnectionUnusedForExactlyPingAfterIdleIsNotPinged(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 30;
        $transport->ack($envelope);
    }

    public function testPingAfterIdleZeroTurnsTheCheckOff(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);
        $transport->now += 3600;
        $transport->ack($envelope);
    }

    /**
     * A pull can wait up to max_batch_timeout for messages, with the connection in use all that time: the idle
     * time counts from when the pull ended, so a long max_batch_timeout does not make every pull PING first.
     */
    public function testIdleTimeCountsFromTheEndOfAPull(): void
    {
        $transport = null;
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnCallback(
            static function () use (&$transport): Future {
                // The pull waits 40 s for messages before the server reports none.
                $transport->now += 40;

                return Future::complete([]);
            },
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30, 'max_batch_timeout' => 40]);
        $transport->setClient($client);
        iterator_to_array($transport->get());
        $transport->now += 5;
        iterator_to_array($transport->get());
    }

    /**
     * The same holds for a pull that ends empty, which the client reports as a 408: counted as use only when
     * messages came, an empty pull longer than ping_after_idle made every next pull PING first, with one
     * connection_timeout for the server to answer.
     */
    public function testIdleTimeCountsFromTheEndOfAnEmptyPull(): void
    {
        $transport = null;
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnCallback(
            static function () use (&$transport): Future {
                // The pull waits 40 s for messages, and none come.
                $transport->now += 40;

                return Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408));
            },
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30, 'max_batch_timeout' => 40]);
        $transport->setClient($client);
        self::assertSame([], iterator_to_array($transport->get()));
        $transport->now += 5;
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * With auto_setup, the connection that replaces one that failed the PING is verified again before it is
     * used, as after close().
     */
    public function testAutoSetupVerifiesTheConnectionThatReplacedAnIdleOne(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));
        $jetStream->expects(self::exactly(2))->method('publish')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn(Future::error(new ConnectionException('Connection lost')));
        $client->method('disconnect')->willReturn(Future::complete());

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30, 'auto_setup' => true], $serializer);
        $transport->setClient($client);
        $transport->send(new Envelope(new \stdClass()));

        $transport->now += 31;
        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * With auto_setup the connection is reached twice in one send() - by the provisioning check, then by the
     * publish - and an answered PING counts as use, so the idle connection is checked once, not twice.
     */
    public function testAnsweredPingCountsAsUseSoOneOperationPingsOnce(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        $jetStream->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));
        $jetStream->expects(self::exactly(2))->method('publish')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30, 'auto_setup' => true], $serializer);
        $transport->setClient($client);
        $transport->send(new Envelope(new \stdClass()));

        $transport->now += 31;
        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * The client can keep reporting a connection Open after its socket died - older clients after a failed
     * write ("The stream is not writable"), 2.10 after the server's fatal -ERR - so an operation that failed
     * on the connection makes the next one check it with a PING first, and move to a new connection when it
     * goes unanswered, instead of failing the same way until the process restarts (#49).
     */
    public function testOperationThatFailedOnTheConnectionMakesTheNextOneCheckItFirst(): void
    {
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::exactly(2))->method('ack')->willReturnOnConsecutiveCalls(
            Future::complete(),
            Future::error(new \RuntimeException('The stream is not writable')),
        );
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->expects(self::exactly(2))->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->expects(self::once())->method('rtt')->willReturn(Future::error(new \RuntimeException('The stream is not writable')));
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        try {
            $transport->ack($envelope);
            self::fail('expected the write to fail');
        } catch (\RuntimeException $e) {
            self::assertSame('The stream is not writable', $e->getMessage());
        }

        // The client still reports Open; no time has passed. The check comes from the failure.
        $transport->ack($envelope);
        // The new connection owes no check.
        $transport->ack($envelope);
    }

    /**
     * close() ends the check a failure called for, like a replaced connection: the connection the next
     * operation opens owes no PING.
     */
    public function testCloseEndsTheCheckAFailureCalledFor(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(4))->method('ack')->willReturnOnConsecutiveCalls(
            Future::complete(),
            Future::error(new \RuntimeException('Broken pipe')),
            Future::complete(),
            Future::complete(),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');
        $client->expects(self::once())->method('disconnect')->willReturnCallback(static function () use (&$state): Future {
            $state = ConnectionState::Closed;

            return Future::complete();
        });

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);
        try {
            $transport->ack($envelope);
        } catch (\RuntimeException) {
            // The failure that would call for the check.
        }
        $transport->close();
        $transport->ack($envelope);
        $transport->ack($envelope);
    }

    /**
     * A connection that answers the PING after a failure - the failure was a slow reply, say - is kept, and
     * the check is not repeated for the operations after it.
     */
    public function testAnsweredPingAfterAFailureKeepsTheConnectionAndEndsTheCheck(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(4))->method('ack')->willReturnOnConsecutiveCalls(
            Future::complete(),
            Future::error(new \RuntimeException('Request timed out')),
            Future::complete(),
            Future::complete(),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));
        $client->expects(self::never())->method('disconnect');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);
        try {
            $transport->ack($envelope);
        } catch (\RuntimeException) {
            // The failure that calls for the check.
        }
        $transport->ack($envelope);
        $transport->ack($envelope);
    }

    /**
     * A JetStream reply proves the server answered, so it calls for no check: an empty pull (408) or a
     * rejected publish does not make the next operation PING. An idle worker pulls empty batches all day.
     */
    public function testJetStreamReplyDoesNotMakeTheNextOperationCheckTheConnection(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturn(
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );
        $jetStream->expects(self::exactly(2))->method('publish')->willReturnOnConsecutiveCalls(
            Future::error(new JetStreamException('maximum messages exceeded', 400)),
            Future::complete(),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30], $serializer);
        $transport->setClient($client);
        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([], iterator_to_array($transport->get()));
        try {
            $transport->send(new Envelope(new \stdClass()));
        } catch (JetStreamException) {
            // Rejected by the server, which therefore answered.
        }
        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * A pull that got no answer before the client's own deadline is no reply, though the client reports it as
     * a JetStream error: a server that is there ends every pull before then, with status 408 when it found no
     * messages. A half-open connection takes the pull request and delivers nothing. The pull reads as an empty
     * batch, and the next operation checks the connection first, so the pull after it runs on a new one.
     */
    public function testPullTheServerDidNotAnswerMakesTheNextOperationCheckTheConnection(): void
    {
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::once())->method('fetchBatch')->willReturn(
            Future::error(new JetStreamException('No messages received within timeout', 408)),
        );
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::exactly(2))->method('fetchBatch')->willReturn(
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->expects(self::exactly(2))->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->expects(self::once())->method('rtt')->willReturn(Future::error(new ConnectionException('Connection lost')));
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        self::assertSame([], iterator_to_array($transport->get()));

        // The client still reports Open, and no time has passed: the check comes from the unanswered pull.
        self::assertSame([], iterator_to_array($transport->get()));
        // The server answered that pull, so the next one owes no check.
        self::assertSame([], iterator_to_array($transport->get()));
    }

    public function testFailureMakesNoOperationCheckTheConnectionWhenTheCheckIsOff(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(3))->method('ack')->willReturnOnConsecutiveCalls(
            Future::complete(),
            Future::error(new \RuntimeException('The stream is not writable')),
            Future::complete(),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);
        try {
            $transport->ack($envelope);
        } catch (\RuntimeException) {
            // Ignored: the check is off.
        }
        $transport->ack($envelope);
    }

    /**
     * When the consumer lookup fails on the connection, getMessageCount() checks it before its stream-level
     * fallback, so the fallback runs on a new connection and still reports the stream's messages.
     */
    /**
     * Against a server that cannot be reached, the count is 0 after a single connection attempt: the stream
     * lookup used to dial a second time, which doubled the wait (#54).
     */
    public function testMessageCountThatCannotConnectDialsOnceAndReturnsZero(): void
    {
        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::error(new ConnectionException('Connection refused'))]);
        $client->expects(self::never())->method('jetStream');

        $transport = new ClockedNatsTransport(self::VALID_DSN, []);
        $transport->setClient($client);

        self::assertSame(0, $transport->getMessageCount());
    }

    public function testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne(): void
    {
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::once())->method('getConsumer')->willReturn(Future::error(new \RuntimeException('Broken pipe')));
        $oldConnection->expects(self::never())->method('getStream');
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::once())->method('getStream')->willReturn(Future::complete(new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: ['state' => ['messages' => 7]],
        )));

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->expects(self::exactly(2))->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->method('rtt')->willReturn(Future::error(new \RuntimeException('Broken pipe')));
        $client->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);

        self::assertSame(7, $transport->getMessageCount());
    }

    /**
     * Symfony calls keepalive() from a signal handler (#48), so it adds no PING or dial: it uses the
     * connection as it is, however long the handler has been running.
     */
    public function testKeepaliveNeitherPingsNorDials(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('ack')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('inProgress')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 600;
        $transport->keepalive($envelope);
    }

    /**
     * A serializer that encodes every envelope to the same body.
     */
    private function encodingSerializer(): SerializerInterface
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        return $serializer;
    }

    /**
     * A JetStream context whose publish() records the subject, the headers and the Nats-Msg-Id of each call.
     *
     * @param list<array{subject: string, headers: array<string, string>, msgId: ?string}>|null $publishes
     */
    private function jetStreamRecordingPublishes(?array &$publishes): JetStreamContext
    {
        $publishes = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('publish')->willReturnCallback(
            static function (string $subject, string $payload, array $headers = [], ?string $msgId = null) use (&$publishes): Future {
                $publishes[] = ['subject' => $subject, 'headers' => $headers, 'msgId' => $msgId];

                return Future::complete();
            },
        );

        return $jetStream;
    }

    /**
     * A client mock whose state() reports $state, which every successful connect() sets to Open, as the
     * real client does; a test closes the connection by setting $state to Closed. Each connect() returns
     * the next of $dials, and is recorded in $calls when given.
     *
     * @param list<Future<null>> $dials
     * @param list<string>|null $calls
     */
    private function clientReportingState(ConnectionState &$state, array $dials, ?array &$calls = null): NatsClient&\PHPUnit\Framework\MockObject\MockObject
    {
        $client = $this->createMock(NatsClient::class);
        $client->method('state')->willReturnCallback(static function () use (&$state): ConnectionState {
            return $state;
        });
        $client->expects(self::exactly(count($dials)))->method('connect')->willReturnCallback(
            static function () use (&$state, &$dials, &$calls): Future {
                if ($calls !== null) {
                    $calls[] = 'connect';
                }

                $dial = array_shift($dials);
                self::assertNotNull($dial);

                return $dial->map(static function () use (&$state): void {
                    $state = ConnectionState::Open;
                });
            },
        );

        return $client;
    }

    /**
     * With retry_handler=nats, NATS redelivers a failed message itself once reject() NAKs it, so the copy
     * Symfony's retry sends is not published: published as well, it retried the same failure twice, and every
     * copy again (#47). Symfony's retry strategy is ignored in nats mode.
     */
    public function testNatsModeDoesNotPublishTheCopySymfonysRetrySends(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('encode');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats'], $serializer);
        $transport->setJetStreamContext($jetStream);
        $retry = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new DelayStamp(1000), new RedeliveryStamp(1)]);

        self::assertSame($retry, $transport->send($retry));
    }

    /**
     * Only in nats mode: with the default retry_handler=symfony, Symfony's retry copy is the retry.
     */
    public function testSymfonyModePublishesTheCopySymfonysRetrySends(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new DelayStamp(1000), new RedeliveryStamp(1)]));
    }

    /**
     * In nats mode a copy for the failure transport (retry count 0) and a message that was not received in
     * this process are still published: only Symfony's retry copy is left out.
     *
     * @param list<\Symfony\Component\Messenger\Stamp\StampInterface> $stamps
     */
    #[DataProvider('envelopesNatsModeStillPublishes')]
    public function testNatsModeStillPublishesWhatIsNotSymfonysRetryCopy(array $stamps): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats'], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass(), $stamps));
    }

    /**
     * @return iterable<string, array{list<\Symfony\Component\Messenger\Stamp\StampInterface>}>
     */
    public static function envelopesNatsModeStillPublishes(): iterable
    {
        yield 'copy for the failure transport' => [[
            new ReceivedStamp('async'),
            new SentToFailureTransportStamp('async'),
            new DelayStamp(0),
            new RedeliveryStamp(0),
        ]];
        yield 'message not received in this process' => [[new RedeliveryStamp(1)]];
        yield 'plain dispatch' => [[]];
    }

    /**
     * Through Symfony's own Worker and retry listener, with the retry strategy FrameworkBundle gives every
     * transport (3 retries): a delivery that fails in nats mode is NAKed for NATS to redeliver, and no copy is
     * published beside it.
     */
    public function testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'encoded'),
        ]));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::never())->method('term');
        $jetStream->expects(self::once())->method('nak')->willReturn(Future::complete());

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('decode')->willReturn(new Envelope(new \stdClass()));
        $serializer->expects(self::never())->method('encode');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats'], $serializer);
        $transport->setJetStreamContext($jetStream);

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new \RuntimeException('always fails');
            }],
        ]))]);
        $locator = static fn (object $service): ContainerInterface => new class ($service) implements ContainerInterface {
            public function __construct(private object $service)
            {
            }

            public function get(string $id): object
            {
                return $this->service;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            $locator($transport),
            $locator(new MultiplierRetryStrategy(3, 1000, 2)),
        ));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
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

    public function testGetMessageCountDefaultsMissingConsumerCountersToZero(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // A consumer-info response missing the counters (older/partial server response) must default
        // both to 0 rather than erroring.
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: [],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(0, $transport->getMessageCount());
    }

    public function testGetMessageCountCoercesStringConsumerCounters(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // JSON-decoded counters can arrive as numeric strings; they must be coerced and summed.
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['num_ack_pending' => '2', 'num_pending' => '5'],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(7, $transport->getMessageCount());
    }

    public function testGetMessageCountFallsBackToStreamState(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // The consumer does not exist yet: JetStream answers 404.
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404)));
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

    /**
     * A stream state without a message count, as in a partial server response, counts as 0.
     */
    public function testGetMessageCountReadsAStreamStateWithoutAMessageCountAsZero(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete(new StreamInfo(
                name: 'test-stream',
                subjects: ['test-topic'],
                raw: ['state' => [], 'config' => ['name' => 'test-stream', 'subjects' => ['test-topic']]],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame(0, $transport->getMessageCount());
    }

    public function testGetMessageCountReturnsZeroWhenLookupsFail(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::error(new JetStreamException('stream not found', 404)));

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

    public function testSetupPassesNewStreamPolicyOptions(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::streamConfigEquals([
                'storage' => 'file',
                'num_replicas' => 1,
                'retention' => 'workqueue',
                'discard' => 'new',
                'duplicate_window' => 30_000_000_000,
                'max_msg_size' => 1048576,
                'max_consumers' => 4,
                'compression' => 's2',
                'description' => 'demo stream',
                'deny_delete' => true,
                'deny_purge' => true,
                'allow_direct' => true,
                'allow_rollup_hdrs' => false,
                'name' => 'test-stream',
                'subjects' => ['test-topic'],
            ]))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_retention' => 'workqueue',
            'stream_discard' => 'new',
            'stream_duplicate_window' => 30,
            'stream_max_message_size' => 1048576,
            'stream_max_consumers' => 4,
            'stream_compression' => 's2',
            'stream_description' => 'demo stream',
            'stream_deny_delete' => true,
            'stream_deny_purge' => true,
            'stream_allow_direct' => true,
            'stream_allow_rollup_headers' => false,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * The four tri-state stream flags are all booleans, so a single set of values cannot prove they are
     * wired to the right fields: any two flags sharing a value could be swapped undetectably. These two
     * runs give every flag a unique true/false signature across the pair, so any cross-wiring shows up
     * in at least one of them.
     *
     * @param array<string, bool> $flags
     * @param array<string, bool> $expected
     */
    #[DataProvider('triStateStreamFlagProvider')]
    public function testSetupPassesEachTriStateStreamFlagIndependently(array $flags, array $expected): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::streamConfigEquals($expected + [
                'storage' => 'file',
                'num_replicas' => 1,
                'name' => 'test-stream',
                'subjects' => ['test-topic'],
            ]))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $flags);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * @return iterable<string, array{array<string, bool>, array<string, bool>}>
     */
    public static function triStateStreamFlagProvider(): iterable
    {
        yield 'deny flags on, allow flags off' => [
            [
                'stream_deny_delete' => true,
                'stream_deny_purge' => true,
                'stream_allow_direct' => false,
                'stream_allow_rollup_headers' => false,
            ],
            [
                'deny_delete' => true,
                'deny_purge' => true,
                'allow_direct' => false,
                'allow_rollup_hdrs' => false,
            ],
        ];

        yield 'alternating so every flag differs from the previous run' => [
            [
                'stream_deny_delete' => true,
                'stream_deny_purge' => false,
                'stream_allow_direct' => true,
                'stream_allow_rollup_headers' => false,
            ],
            [
                'deny_delete' => true,
                'deny_purge' => false,
                'allow_direct' => true,
                'allow_rollup_hdrs' => false,
            ],
        ];
    }

    public function testSetupPassesNewConsumerOptions(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        // The consumer does not exist yet, so replay_policy is safe to write.
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404)));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::consumerConfigEquals([
                'durable_name' => 'client',
                'filter_subject' => 'test-topic',
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
                'max_ack_pending' => 256,
                'inactive_threshold' => 2_000_000_000,
                'replay_policy' => 'original',
            ]))
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'max_ack_pending' => 256,
            'inactive_threshold' => 2,
            'replay_policy' => 'original',
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupPreservesTheExistingConsumerReplayPolicyOverTheConfiguredOne(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->with('test-stream', 'client')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                    'filter_subject' => 'test-topic',
                    'replay_policy' => 'instant',
                ]],
            )));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $configuration): bool {
                // NATS rejects any change to a durable's replay policy, so the live consumer's own
                // value is sent back rather than the configured one.
                return ($configuration->toArray()['replay_policy'] ?? null) === 'instant';
            }))
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['replay_policy' => 'original']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupWritesReplayPolicyWhenExistingConsumerAlreadyMatches(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                    'filter_subject' => 'test-topic',
                    'replay_policy' => 'original',
                ]],
            )));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $configuration): bool {
                return ($configuration->toArray()['replay_policy'] ?? null) === 'original';
            }))
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['replay_policy' => 'original']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupOmitsReplayPolicyWhenTheExistingConsumerReportsNone(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        // Server reported no replay_policy at all, so it is on its default and the field is left out
        // rather than sent to a server that may predate it.
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $configuration): bool {
                return !array_key_exists('replay_policy', $configuration->toArray());
            }))
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['replay_policy' => 'instant']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupRethrowsNon404JetStreamExceptionFromConsumerLookup(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('permissions violation', 403)));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['replay_policy' => 'original']);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('permissions violation');

        $transport->setup();
    }

    public function testAutoSetupProvisionsOnFirstSendOnce(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));
        $jetStream->expects(self::exactly(2))
            ->method('publish')
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        // Two sends: setup() must run exactly once (addStream/addConsumer once each), publish twice.
        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new \stdClass()));
    }

    public function testAutoSetupProvisionsOnFirstGet(): void
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
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    public function testAutoSetupDisabledByDefaultDoesNotProvisionOnSend(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('addStream');
        $jetStream->expects(self::never())->method('addConsumer');
        $jetStream->expects(self::once())
            ->method('publish')
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass()));
    }

    public function testAutoSetupProvisionsBeforeTheFirstPublish(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        // Recording the order matters: provisioning that runs *after* the publish would still satisfy
        // simple call-count expectations while defeating the entire point of auto_setup.
        $calls = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturnCallback(static function () use (&$calls) {
            $calls[] = 'addStream';

            return Future::complete();
        });
        $jetStream->method('addConsumer')->willReturnCallback(static function () use (&$calls) {
            $calls[] = 'addConsumer';

            return Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            ));
        });
        $jetStream->method('publish')->willReturnCallback(static function () use (&$calls) {
            $calls[] = 'publish';

            return Future::complete();
        });

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass()));

        self::assertSame(['addStream', 'addConsumer', 'publish'], $calls);
    }

    public function testAutoSetupProvisionsBeforeTheFirstFetch(): void
    {
        $calls = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturnCallback(static function () use (&$calls) {
            $calls[] = 'addStream';

            return Future::complete();
        });
        $jetStream->method('addConsumer')->willReturnCallback(static function () use (&$calls) {
            $calls[] = 'addConsumer';

            return Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            ));
        });
        $jetStream->method('fetchBatch')->willReturnCallback(static function () use (&$calls) {
            $calls[] = 'fetchBatch';

            return Future::complete([]);
        });

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        iterator_to_array($transport->get());

        self::assertSame(['addStream', 'addConsumer', 'fetchBatch'], $calls);
    }

    public function testAutoSetupRetriesProvisioningAfterAFailedAttempt(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $jetStream = $this->createMock(JetStreamContext::class);
        // First provisioning attempt fails outright, second succeeds. The done-flag must not be set by
        // the failed attempt, or the transport would publish to a stream it never created.
        $jetStream->expects(self::exactly(2))
            ->method('addStream')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('backend unavailable', 500)),
                Future::complete(),
            );
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::error(new JetStreamException('stream not found', 404)));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->send(new Envelope(new \stdClass()));
            self::fail('The first send() was expected to fail while provisioning.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('backend unavailable', $exception->getMessage());
        }

        $transport->send(new Envelope(new \stdClass()));
    }

    public function testAutoSetupReprovisionsWhenTheConsumerDisappeared(): void
    {
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );

        $jetStream = $this->createMock(JetStreamContext::class);
        // Once for the initial lazy provisioning, once after the 404.
        $jetStream->expects(self::exactly(2))
            ->method('addStream')
            ->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('addConsumer')
            ->willReturn(Future::complete($consumerInfo));
        // A deleted durable consumer leaves nothing subscribed to answer the pull, which the client
        // reports as 503 (verified against nats-server 2.10.29 and 2.14.2) - NOT 404. The retry after
        // re-provisioning succeeds and simply finds no messages.
        $jetStream->expects(self::exactly(2))
            ->method('fetchBatch')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('JetStream pull request ended with status 503', 503)),
                Future::complete([]),
            );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    public function testGetTreats404AsEmptyWithoutReprovisioningWhenAutoSetupIsDisabled(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('addStream');
        $jetStream->expects(self::never())->method('addConsumer');
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    public function testAutoSetupReprovisioningRetryStopsAfterOneAttempt(): void
    {
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($consumerInfo));
        // Still 404 after re-provisioning: report an empty batch rather than looping.
        $jetStream->expects(self::exactly(2))
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    public function testAutoSetupRethrowsAnUnexpectedErrorFromTheRetriedPull(): void
    {
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($consumerInfo));
        // The retry after re-provisioning hits a genuine error, which must surface rather than be
        // flattened into an empty batch like a 404 or 408 would be.
        $jetStream->expects(self::exactly(2))
            ->method('fetchBatch')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('consumer not found', 404)),
                Future::error(new JetStreamException('backend unavailable', 500)),
            );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('backend unavailable');

        iterator_to_array($transport->get());
    }

    /**
     * Only a status that can mean a missing stream or consumer calls for provisioning again: any other error
     * from the pull propagates as it is.
     */
    public function testAutoSetupDoesNotReprovisionForAnUnexpectedPullError(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        // Once, for the first use.
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('backend unavailable', 500)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('backend unavailable');

        iterator_to_array($transport->get());
    }

    /**
     * After provisioning again, a retried pull that found no messages (408) is an empty batch, not an error.
     */
    public function testAutoSetupReadsA408FromTheRetriedPullAsEmpty(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));
        $jetStream->expects(self::exactly(2))
            ->method('fetchBatch')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('JetStream pull request ended with status 503', 503)),
                Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
            );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    #[DataProvider('missingResourceStatusProvider')]
    public function testAutoSetupReprovisionsForEveryMissingResourceStatus(int $status): void
    {
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($consumerInfo));
        $jetStream->expects(self::exactly(2))
            ->method('fetchBatch')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('missing', $status)),
                Future::complete([]),
            );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], array_values(iterator_to_array($transport->get())));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function missingResourceStatusProvider(): iterable
    {
        yield '404 stream or consumer lookup' => [404];
        yield '409 consumer deleted mid-pull' => [409];
        yield '503 nothing answers the pull' => [503];
    }

    public function testGetStillPropagates503WhenAutoSetupIsDisabled(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('addStream');
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::error(new JetStreamException('JetStream pull request ended with status 503', 503)));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        // Historical contract for the default configuration: only 404 and 408 read as an empty queue,
        // anything else surfaces so the operator sees it.
        $this->expectException(JetStreamException::class);

        iterator_to_array($transport->get());
    }

    public function testSetupPreservesAnOriginalReplayPolicyWhenTheOptionIsRemoved(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => [
                    'ack_policy' => 'explicit',
                    'deliver_policy' => 'all',
                    'filter_subject' => 'test-topic',
                    'replay_policy' => 'original',
                ]],
            )));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static function (ConsumerConfiguration $configuration): bool {
                // Omitting the field would read as a change back to the server default and be
                // rejected, so the consumer's own 'original' has to be echoed even though the DSN no
                // longer mentions replay_policy at all.
                return ($configuration->toArray()['replay_policy'] ?? null) === 'original';
            }))
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

    public function testCloseResetsAutoSetupSoTheNextOperationProvisionsAgain(): void
    {
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($consumerInfo));
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturn(Future::complete([]));

        $client = $this->createMock(NatsClient::class);
        $client->expects(self::exactly(2))->method('connect')->willReturn(Future::complete());
        $client->expects(self::exactly(2))->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());
        $client->method('state')->willReturn(ConnectionState::Open);

        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setClient($client);

        iterator_to_array($transport->get());
        $transport->close();
        // The reopened connection provisions again instead of trusting the latched flag.
        iterator_to_array($transport->get());
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

    /**
     * With scheduled_messages on, a server that rejects some other feature gets the message that names that
     * feature, not the one about scheduled messages. The exception keeps the client's as its previous one.
     */
    public function testSetupNamesTheRejectedFeatureWhenItIsNotTheOneScheduledMessagesNeed(): void
    {
        $unsupported = new UnsupportedFeatureException('allow_atomic', '2.12', '2.11.0', 'unknown field "allow_atomic"', 400);
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::error($unsupported));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->setup();
            self::fail('setup() was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                "NATS Server feature 'allow_atomic' requires version >= 2.12, but the connected server reports 2.11.0.",
                $exception->getMessage(),
            );
            self::assertSame(0, $exception->getCode());
            self::assertSame($unsupported, $exception->getPrevious());
        }
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

    public function testAssertConsumerMatchesConfigurationRejectsMissingConfig(): void
    {
        $transport = new TestableNatsTransport(self::VALID_DSN, []);
        $method = (new \ReflectionClass($transport))->getMethod('assertConsumerMatchesConfiguration');
        // No 'config' key at all: the is_array(...) ? ... : [] fallback yields an empty config, so the
        // ack-policy check rejects it instead of reading from a non-array (older/partial server response).
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: [],
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

    public function testSendDelayedMessageSchedulesAtRequestedDelay(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willReturn(['body' => 'encoded-payload']);

        $capturedHeaders = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturnCallback(
            function (string $subject, string $payload, array $headers) use (&$capturedHeaders): Future {
                $capturedHeaders = $headers;

                return Future::complete();
            }
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $before = time();
        $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));

        self::assertArrayHasKey('Nats-Schedule', $capturedHeaders);
        self::assertStringStartsWith('@at ', $capturedHeaders['Nats-Schedule']);
        // The DelayStamp(5000) must be honored, and the whole-second @at resolution is rounded UP, so
        // the scheduled time is never before the requested delay (5s) and at most ~1s beyond it.
        $scheduled = new \DateTimeImmutable(substr($capturedHeaders['Nats-Schedule'], 4));
        $delaySeconds = $scheduled->getTimestamp() - $before;
        self::assertGreaterThanOrEqual(5, $delaySeconds);
        self::assertLessThanOrEqual(7, $delaySeconds);
    }

    public function testSendDelayedMessageNeverSchedulesBeforeRequestedDelay(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willReturn(['body' => 'encoded-payload']);

        $capturedHeaders = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturnCallback(
            function (string $subject, string $payload, array $headers) use (&$capturedHeaders): Future {
                $capturedHeaders = $headers;

                return Future::complete();
            }
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        // A delay of a second or less must NOT collapse to "now": the @at schedule has whole-second
        // resolution, and the delivery time is rounded up to it. The delay is picked so that the requested
        // time falls in the middle of a second, where rounding up and cutting the fraction off give different
        // seconds, so the check holds whatever the clock reads when the test starts.
        $now = new \DateTimeImmutable();
        $delayMs = 1 + (1499 - intdiv((int) $now->format('u'), 1000)) % 1000;
        $requested = $now->modify(sprintf('+%d milliseconds', $delayMs));
        $transport->send(new Envelope(new \stdClass(), [new DelayStamp($delayMs)]));

        self::assertArrayHasKey('Nats-Schedule', $capturedHeaders);
        $scheduled = new \DateTimeImmutable(substr($capturedHeaders['Nats-Schedule'], 4));
        self::assertGreaterThanOrEqual($requested, $scheduled, 'A short delay must not be scheduled before it has elapsed.');
    }

    public function testSendDelayedMessageWithLargeDelaySchedulesFarInTheFuture(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willReturn(['body' => 'encoded-payload']);

        $capturedHeaders = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturnCallback(
            function (string $subject, string $payload, array $headers) use (&$capturedHeaders): Future {
                $capturedHeaders = $headers;

                return Future::complete();
            }
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        // A 1-hour delay must schedule ~3600s out with no precision loss or overflow, and never earlier.
        $before = time();
        $transport->send(new Envelope(new \stdClass(), [new DelayStamp(3_600_000)]));

        self::assertArrayHasKey('Nats-Schedule', $capturedHeaders);
        $delaySeconds = (new \DateTimeImmutable(substr($capturedHeaders['Nats-Schedule'], 4)))->getTimestamp() - $before;
        self::assertGreaterThanOrEqual(3600, $delaySeconds);
        self::assertLessThanOrEqual(3602, $delaySeconds);
    }

    public function testSendPublishesLargePayloadWithoutTruncation(): void
    {
        // 1 MiB body - exercises that the transport never truncates or mangles a large serialized
        // envelope on the publish path (the exact bytes must reach the client unchanged).
        $largeBody = str_repeat('A', 1024 * 1024);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willReturn(['body' => $largeBody]);

        $capturedPayload = null;
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturnCallback(
            function (string $subject, string $payload) use (&$capturedPayload): Future {
                $capturedPayload = $payload;

                return Future::complete();
            }
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass()));

        self::assertSame(1024 * 1024, strlen((string) $capturedPayload));
        self::assertSame($largeBody, $capturedPayload);
    }

    public function testSendTwoDelayedMessagesUseDistinctDelayedSubjects(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::exactly(2))->method('encode')->willReturn(['body' => 'encoded-payload']);

        $subjects = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('publish')->willReturnCallback(
            function (string $subject, string $payload, array $headers) use (&$subjects): Future {
                $subjects[] = $subject;

                return Future::complete();
            }
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));
        $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));

        self::assertCount(2, $subjects);
        // Each delayed message gets its own UUID-suffixed subject so two never collide on the stream.
        self::assertNotSame($subjects[0], $subjects[1]);
        self::assertMatchesRegularExpression('/^test-topic\.delayed\.[0-9a-f-]{36}$/', $subjects[0]);
        self::assertMatchesRegularExpression('/^test-topic\.delayed\.[0-9a-f-]{36}$/', $subjects[1]);
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
            ->with('test-topic', 'encoded-payload', [])
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(5000)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    /**
     * With deduplicate, a message gets an id on its first send, its transport message id. The id is encoded
     * with the message, so that it travels with it, and is published as its Nats-Msg-Id with the retry count
     * (#53).
     */
    public function testSendWithDeduplicationStampsTheEnvelopeAndPublishesItsMessageId(): void
    {
        $encoded = null;
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('encode')->willReturnCallback(static function (Envelope $envelope) use (&$encoded): array {
            $encoded = $envelope;

            return ['body' => 'encoded'];
        });

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['deduplicate' => true], $serializer);
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));

        $sent = $transport->send(new Envelope(new \stdClass()));

        $id = $sent->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);
        self::assertSame($id, $sent->last(DeduplicationIdStamp::class)?->id);
        self::assertInstanceOf(Envelope::class, $encoded);
        self::assertSame($id, $encoded->last(DeduplicationIdStamp::class)?->id, 'The id must be encoded with the message.');
        self::assertSame([$id . ':test-topic:0'], array_column($publishes, 'msgId'));
    }

    /**
     * A copy sent again keeps the id it carries, such as Symfony's retry of a received message, instead of
     * getting a new one, so that JetStream can drop it.
     */
    public function testSendKeepsTheDeduplicationIdTheEnvelopeCarries(): void
    {
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['deduplicate' => true], $this->encodingSerializer());
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));

        $sent = $transport->send(new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42')]));

        self::assertCount(1, $sent->all(DeduplicationIdStamp::class));
        self::assertSame(['order-42:test-topic:0'], array_column($publishes, 'msgId'));
    }

    /**
     * Each of Symfony's retries is a message of its own, and so is the copy for a failure transport, which
     * Symfony sends with a retry count of 0, like the original.
     */
    public function testSendGivesEachRetryAndTheFailureTransportCopyAMessageIdOfItsOwn(): void
    {
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['deduplicate' => true], $this->encodingSerializer());
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));
        $original = new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42')]);

        $transport->send($original);
        $transport->send($original->with(new RedeliveryStamp(1)));
        $transport->send($original->with(new RedeliveryStamp(2)));
        $transport->send($original->with(new SentToFailureTransportStamp('async'), new RedeliveryStamp(0)));

        self::assertSame(['order-42:test-topic:0', 'order-42:test-topic:1', 'order-42:test-topic:2', 'order-42:test-topic:0:failed:async'], array_column($publishes, 'msgId'));
    }

    /**
     * Off by default: no id is added, and nothing is deduplicated.
     */
    public function testSendWithoutDeduplicationSendsNoMessageIdAndAddsNoStamp(): void
    {
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $this->encodingSerializer());
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));

        $sent = $transport->send(new Envelope(new \stdClass()));

        self::assertNull($sent->last(DeduplicationIdStamp::class));
        self::assertSame([null], array_column($publishes, 'msgId'));
    }

    /**
     * An id the application adds itself is used with the option off as well: it asked for it.
     */
    public function testSendUsesAnApplicationDeduplicationIdWithTheOptionOff(): void
    {
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $this->encodingSerializer());
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));

        $transport->send(new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42')]));

        self::assertSame(['order-42:test-topic:0'], array_column($publishes, 'msgId'));
    }

    /**
     * A delayed message keeps its id too: NATS does not pass the Nats-Msg-Id on to the message it delivers at
     * the scheduled time, so the delivery cannot be taken for a duplicate (measured on 2.12 and 2.14).
     */
    public function testSendDelayedMessageWithDeduplicationPublishesItsMessageId(): void
    {
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['deduplicate' => true, 'scheduled_messages' => true], $this->encodingSerializer());
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));

        $sent = $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));

        $id = $sent->last(DeduplicationIdStamp::class)?->id;
        self::assertNotNull($id);
        self::assertCount(1, $publishes);
        self::assertSame('test-topic.delayed.' . $id, $publishes[0]['subject']);
        self::assertArrayHasKey('Nats-Schedule', $publishes[0]['headers']);
        self::assertSame($id . ':test-topic:0', $publishes[0]['msgId']);
    }

    /**
     * Symfony hands each transport a message is routed to the envelope the one before returned, deduplication
     * id included. JetStream deduplicates per stream whatever the subject, so two transports on one stream
     * dropped the second copy; the subject keeps their ids apart.
     */
    public function testSendGivesTheCopiesForTwoTransportsOnOneStreamMessageIdsOfTheirOwn(): void
    {
        $orders = new RuntimeTestableNatsTransport('nats://localhost:4222/events/events.orders', ['deduplicate' => true], $this->encodingSerializer());
        $orders->setJetStreamContext($this->jetStreamRecordingPublishes($ordersPublishes));
        $payments = new RuntimeTestableNatsTransport('nats://localhost:4222/events/events.payments', [], $this->encodingSerializer());
        $payments->setJetStreamContext($this->jetStreamRecordingPublishes($paymentsPublishes));

        $sent = $payments->send($orders->send(new Envelope(new \stdClass())));

        $id = $sent->last(DeduplicationIdStamp::class)?->id;
        self::assertNotNull($id);
        self::assertSame([$id . ':events.orders:0'], array_column($ordersPublishes, 'msgId'));
        self::assertSame([$id . ':events.payments:0'], array_column($paymentsPublishes, 'msgId'));
    }

    /**
     * A message routed to two transports that fails on both reaches a shared failure transport twice, with
     * the same deduplication id and retry count: the transport it failed on keeps the two copies apart.
     */
    public function testSendGivesTheFailureCopiesOfTwoTransportsMessageIdsOfTheirOwn(): void
    {
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['deduplicate' => true], $this->encodingSerializer());
        $transport->setJetStreamContext($this->jetStreamRecordingPublishes($publishes));
        $failed = new Envelope(new \stdClass(), [new DeduplicationIdStamp('order-42'), new RedeliveryStamp(0)]);

        $transport->send($failed->with(new SentToFailureTransportStamp('orders')));
        $transport->send($failed->with(new SentToFailureTransportStamp('payments')));

        self::assertSame(['order-42:test-topic:0:failed:orders', 'order-42:test-topic:0:failed:payments'], array_column($publishes, 'msgId'));
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
            ->with('test-topic', 'encoded-payload', [])
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelope = new Envelope(new \stdClass(), [new DelayStamp(0)]);
        $result = $transport->send($envelope);

        self::assertInstanceOf(TransportMessageIdStamp::class, $result->last(TransportMessageIdStamp::class));
    }

    /**
     * With scheduled_messages on, a message without a DelayStamp goes to the topic at once, with no schedule
     * headers.
     */
    public function testSendWithoutDelayStampPublishesNormallyWhenScheduledMessagesAreEnabled(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('encode')->willReturn(['body' => 'encoded-payload']);

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('publish')
            ->with('test-topic', 'encoded-payload', [])
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass()));
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

    public function testSetupUpdateRemovesOrphanedDelayedSubjectWhenScheduledMessagesDisabled(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic', 'test-topic.delayed.>', 'operator.added'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic', 'test-topic.delayed.>', 'operator.added'],
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
            // scheduled_messages is off, so the transport-managed '{topic}.delayed.>' subject left over
            // from a previous run must be dropped, while the plain topic and any operator-added subject
            // are preserved. What is left must still be a list: with a gap in its keys it would be sent as
            // a JSON object, which the server rejects.
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['subjects'] ?? null) === ['test-topic', 'operator.added']))
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

    /**
     * A configured stream_max_bytes replaces the existing stream's limit on update.
     */
    public function testSetupUpdatesExistingStreamWithMaxBytes(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $streamInfo = new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: ['config' => ['name' => 'test-stream', 'subjects' => ['test-topic'], 'storage' => 'file', 'max_bytes' => 1024]],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['max_bytes'] ?? null) === 2048))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_max_bytes' => 2048]);
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
                    'max_msg_size' => 1_048_576,
                    // Set by an operator outside this transport. NATS refuses to change it on an
                    // existing stream up to 2.11, so it must survive the update untouched.
                    'max_consumers' => 3,
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
                    && ($options['max_msgs_per_subject'] ?? null) === -1
                    // Preserved, not reset: neither field was written by this transport before the
                    // options existed, so an operator-set value must survive an upgrade.
                    && ($options['max_msg_size'] ?? null) === 1_048_576
                    && ($options['max_consumers'] ?? null) === 3;
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

    public function testSetupUpdateClampsInheritedDuplicateWindowToTheConfiguredMaxAge(): void
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
                    // The server default window, inherited because stream_duplicate_window is unset.
                    'duplicate_window' => 120_000_000_000,
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
            ->with('test-stream', self::callback(static function (array $options): bool {
                // 60s max age with a 120s window is rejected by NATS, so the window is clamped down.
                return ($options['max_age'] ?? null) === 60_000_000_000
                    && ($options['duplicate_window'] ?? null) === 60_000_000_000;
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_max_age' => 60]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdateKeepsDuplicateWindowWhenMaxAgeIsUnlimited(): void
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
                    'duplicate_window' => 120_000_000_000,
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
            ->with('test-stream', self::callback(static function (array $options): bool {
                // max_age 0 means unlimited, so any window is valid and nothing is clamped.
                return ($options['max_age'] ?? null) === 0
                    && ($options['duplicate_window'] ?? null) === 120_000_000_000;
            }))
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

    public function testSetupUpdateNeverCancelsAnExistingDenyFlag(): void
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
                    'deny_delete' => true,
                    'deny_purge' => true,
                    'allow_direct' => true,
                ],
            ],
        );
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())->method('getStream')->willReturn(Future::complete($streamInfo));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static function (array $options): bool {
                // NATS rejects an update that cancels either deny, so the DSN's false is ignored for
                // those two. allow_direct is freely mutable, so the configured false must win there.
                return ($options['deny_delete'] ?? null) === true
                    && ($options['deny_purge'] ?? null) === true
                    && ($options['allow_direct'] ?? null) === false;
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_deny_delete' => false,
            'stream_deny_purge' => false,
            'stream_allow_direct' => false,
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdatePreservesServerRetentionOverAConfiguredOne(): void
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
                    'retention' => 'limits',
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
            ->with('test-stream', self::callback(static function (array $options): bool {
                // The DSN asks for workqueue, the live stream is on limits. NATS rejects a retention
                // change, so the server value has to win.
                return ($options['retention'] ?? null) === 'limits';
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_retention' => 'workqueue']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdateAppliesExplicitlyConfiguredStreamMaxMessageSize(): void
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
                    'max_msg_size' => 1_048_576,
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
            ->with('test-stream', self::callback(static function (array $options): bool {
                // Configured explicitly, so the operator's new value wins over the server's.
                return ($options['max_msg_size'] ?? null) === 2048;
            }))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_max_message_size' => 2048]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    public function testSetupUpdateAppliesExplicitlyConfiguredStreamMaxConsumers(): void
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
                    'max_consumers' => 3,
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
            ->with('test-stream', self::callback(static function (array $options): bool {
                return ($options['max_consumers'] ?? null) === 7;
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

        // The option was set explicitly, so the operator's intent wins over the server value. NATS
        // 2.12 and newer accept the change; older servers reject it with their own error, which is the
        // documented trade-off of setting this option on an already-created stream.
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_max_consumers' => 7]);
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

    /**
     * Subjects in the server's config that are not non-empty strings are left out of the update, and the
     * subjects sent back are a list, which JSON encodes as an array, with scheduled messages on or off.
     *
     * @param list<string> $expected
     */
    #[DataProvider('subjectsAfterCleaningServerSubjects')]
    public function testSetupUpdateLeavesOutServerSubjectsThatAreNotNonEmptyStrings(bool $scheduledMessages, array $expected): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete(StreamInfo::fromArray([
                'config' => ['name' => 'test-stream', 'subjects' => [42, '', 'operator.added'], 'storage' => 'file'],
            ])));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static fn (array $options): bool => ($options['subjects'] ?? null) === $expected))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => $scheduledMessages]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * @return iterable<string, array{bool, list<string>}>
     */
    public static function subjectsAfterCleaningServerSubjects(): iterable
    {
        yield 'scheduled messages off' => [false, ['operator.added', 'test-topic']];
        yield 'scheduled messages on' => [true, ['operator.added', 'test-topic', 'test-topic.delayed.>']];
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

    /**
     * A failed setup keeps the failure as its previous exception and does not take over its code.
     */
    public function testSetupFailureKeepsTheCauseAsThePreviousException(): void
    {
        $cause = new JetStreamException('consumer creation failed', 500);
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::error($cause));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->setup();
            self::fail('setup() was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame("Failed to setup NATS stream 'test-stream': consumer creation failed", $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertSame($cause, $exception->getPrevious());
        }
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
            ->with('test-topic', 'encoded-payload', [])
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
