<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\DeferredFuture;
use Amp\Future;
use Composer\InstalledVersions;
use IDCT\NATS\Connection\ConnectionStats;
use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\AuthenticationException;
use IDCT\NATS\Exception\ConnectionException;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\Exception\SubscriptionLimitException;
use IDCT\NATS\Exception\TimeoutException;
use IDCT\NATS\Exception\UnsupportedFeatureException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NATS\Protocol\ServerInfo;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use IDCT\NatsMessenger\Tests\Support\RecordingLogger;
use IDCT\NatsMessenger\Tests\Support\StrictHeaderNameSerializer;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
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
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class TestableNatsTransport extends NatsTransport
{
    /** How often {@see pause()} may run in one test: whileTheClusterSettles() pauses at most 50 times in its 5 s. */
    private const MAX_PAUSES = 60;

    private int $pausesTaken = 0;

    protected function connect(): void
    {
        // Keep unit tests isolated from live NATS.
    }

    /**
     * The real pause, failing the test once it ran more often than setup() ever pauses, so that a loop which never
     * ends fails within seconds instead of hanging the run.
     */
    protected function pause(float $seconds): void
    {
        if (++$this->pausesTaken > self::MAX_PAUSES) {
            throw new \LogicException(sprintf('pause() ran more than %d times: a loop that never ends.', self::MAX_PAUSES));
        }

        parent::pause($seconds);
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

/**
 * Records the pauses setup() takes while the cluster settles instead of waiting, and moves the clock it reads by
 * $secondsPerPause for each of them.
 */
class PausingNatsTransport extends RuntimeTestableNatsTransport
{
    public float $now = 1000.0;

    public float $secondsPerPause = 0.0;

    /** @var list<float> */
    public array $pauses = [];

    protected function monotonicSeconds(): float
    {
        return $this->now;
    }

    protected function pause(float $seconds): void
    {
        $this->pauses[] = $seconds;
        $this->now += $this->secondsPerPause;
        // A loop that never ends, with a clock that does not move, fails the test instead of filling the memory.
        if (count($this->pauses) > 1000) {
            throw new \LogicException('pause() ran more than 1000 times: a loop that never ends.');
        }
    }
}

/**
 * Sends a delayed message as due at a time the test gives instead of the clock's, and records the delays it was asked
 * about.
 */
class DeliveryTimeNatsTransport extends RuntimeTestableNatsTransport
{
    public \DateTimeImmutable $dueAt;

    /** @var list<int> */
    public array $delays = [];

    protected function deliveryTime(int $delayMs): \DateTimeImmutable
    {
        $this->delays[] = $delayMs;

        return $this->dueAt;
    }
}

final class NatsTransportTest extends TestCase
{
    private const VALID_DSN = 'nats://admin:password@localhost:4222/test-stream/test-topic';

    /**
     * retry_handler nats, with the connection check that the NAK of a failed delivery runs first turned off: the
     * tests using it are about what is NAKed, against a JetStream double with no client to answer a PING, and the
     * check has tests of its own (testSettlingAFailedDeliveryChecksTheConnectionFirst and those after it).
     */
    private const NATS_MODE = ['retry_handler' => 'nats', 'ping_after_idle' => 0];

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

    public function testGetLeavesOutHeadersWhoseNameIsAnInteger(): void
    {
        // Any publisher can send a header named "1" or "-5". The client returns such a name as an int key, as PHP
        // stores such array keys, while decode() takes string names. This serializer reads each name as a string
        // under strict types, as an application's own may, so an int key makes it throw a TypeError, and get()
        // would then TERM the message. The transport leaves those headers out and the message decodes with the
        // rest. "007" is no integer to PHP, so that header stays.
        $serializer = new StrictHeaderNameSerializer();

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('fetchBatch')
            ->willReturn(Future::complete([
                new NatsMessage('test-topic', 1, 'reply-id', 'payload', "NATS/1.0\r\n1:x\r\nfoo:bar\r\n-5:y\r\n007:z\r\n\r\n"),
            ]));
        $jetStream->expects(self::never())->method('term');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);

        $envelopes = array_values(iterator_to_array($transport->get()));

        self::assertSame([['body' => 'payload', 'headers' => ['foo' => 'bar', '007' => 'z']]], $serializer->decoded);
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

    /**
     * A pull that finds no messages ends with an exception raised in the event loop while get() waits for it. With
     * zend.exception_ignore_args off, the default without a php.ini, the trace of that exception records the
     * arguments of every frame waiting on it, so it must not record the pull's Future, which holds the exception:
     * such a cycle is freed only by the cycle collector, and an idle worker pulls about once a second.
     */
    public function testAPullThatFindsNoMessagesLeavesNoReferenceCycle(): void
    {
        if (function_exists('xdebug_info') && in_array('develop', (array) xdebug_info('mode'), true)) {
            self::markTestSkipped('Xdebug\'s develop mode keeps thrown exceptions of its own, so the cycles collected here do not tell whether the transport leaves any.');
        }

        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($ignoreArgs, 'The test needs zend.exception_ignore_args off, the default without a php.ini.');

        try {
            $jetStream = $this->createStub(JetStreamContext::class);
            $jetStream->method('fetchBatch')->willReturnCallback(static function (): Future {
                $pull = new DeferredFuture();
                EventLoop::defer(static fn () => $pull->error(new JetStreamException('Request Timeout', 408)));

                return $pull->getFuture();
            });

            $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $this->createStub(SerializerInterface::class));
            $transport->setJetStreamContext($jetStream);

            // The first pull also builds the test double's own cycles, which are made once.
            iterator_to_array($transport->get());
            gc_collect_cycles();

            self::assertSame([], iterator_to_array($transport->get()));
            self::assertSame(0, gc_collect_cycles());
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }

    /**
     * A failed call on the connection leaves no reference cycle behind, whichever operation made it, as an empty pull
     * leaves none (testAPullThatFindsNoMessagesLeavesNoReferenceCycle). awaitOnConnection()'s parameter type keeps a
     * Future out of its own frame, but not out of a closure that captures one made before the call, which would bring
     * the cycle back at that call site; only a test of each operation sees that.
     *
     * @param array<string, mixed> $options
     * @param list<string> $failingCalls
     * @param \Closure(NatsTransport): mixed $operation
     */
    #[DataProvider('operationsWhoseCallFails')]
    public function testAFailedCallLeavesNoReferenceCycle(array $options, array $failingCalls, \Closure $operation): void
    {
        if (function_exists('xdebug_info') && in_array('develop', (array) xdebug_info('mode'), true)) {
            self::markTestSkipped('Xdebug\'s develop mode keeps thrown exceptions of its own, so the cycles collected here do not tell whether the transport leaves any.');
        }

        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($ignoreArgs, 'The test needs zend.exception_ignore_args off, the default without a php.ini.');

        try {
            $failed = 0;
            $jetStream = $this->createStub(JetStreamContext::class);
            foreach ($failingCalls as $call) {
                $jetStream->method($call)->willReturnCallback(static function () use (&$failed): Future {
                    ++$failed;
                    $reply = new DeferredFuture();
                    // Made in the event loop while the operation waits, as the client makes the error of a reply: a
                    // trace is taken where its exception is constructed.
                    EventLoop::defer(static fn () => $reply->error(new JetStreamException('No responders available for request', 503)));

                    return $reply->getFuture();
                });
            }

            $serializer = $this->createStub(SerializerInterface::class);
            $serializer->method('encode')->willReturn(['body' => 'encoded']);
            // ping_after_idle 0: the double has no client to answer the PING a failure, or a reject(), asks for.
            $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options + ['ping_after_idle' => 0], $serializer);
            $transport->setJetStreamContext($jetStream);

            // The first call also builds the test double's own cycles, which are made once.
            self::runIgnoringItsFailure($operation, $transport);
            gc_collect_cycles();

            self::runIgnoringItsFailure($operation, $transport);
            self::assertSame(2 * count($failingCalls), $failed, 'each call failed, on both runs');
            self::assertSame(0, gc_collect_cycles());
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, \Closure(NatsTransport): mixed}>
     */
    public static function operationsWhoseCallFails(): iterable
    {
        $received = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));

        yield 'send' => [[], ['publish'], static fn (NatsTransport $transport) => $transport->send(new Envelope(new \stdClass()))];
        yield 'ack' => [[], ['ack'], static fn (NatsTransport $transport) => $transport->ack($received)];
        yield 'ack with ack_sync' => [['ack_sync' => true], ['ackSync'], static fn (NatsTransport $transport) => $transport->ack($received)];
        yield 'reject, TERM' => [[], ['term'], static fn (NatsTransport $transport) => $transport->reject($received)];
        yield 'reject in nats mode, NAK' => [['retry_handler' => 'nats'], ['nak'], static fn (NatsTransport $transport) => $transport->reject($received)];
        yield 'getMessageCount, both lookups failing' => [[], ['getConsumer', 'getStream'], static fn (NatsTransport $transport) => $transport->getMessageCount()];
        yield 'setup, the stream refused' => [[], ['addStream', 'getStream'], static fn (NatsTransport $transport) => $transport->setup()];
    }

    /**
     * @param \Closure(NatsTransport): mixed $operation
     */
    private static function runIgnoringItsFailure(\Closure $operation, NatsTransport $transport): void
    {
        try {
            $operation($transport);
        } catch (\Throwable) {
            // What the failure leaves behind is what the test counts.
        }
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

    /**
     * Before Symfony Messenger 8.1, a message the serializer cannot decode is TERMed and logged, and the rest of the
     * batch is still received: the worker keeps running, in every mode. Up to 5.9.x get() rethrew the decode error,
     * which stopped the worker, and nats mode NAKed the message first, so that every new worker got it again.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('modesThatReceiveAMessageThatCannotBeDecoded')]
    public function testBeforeMessenger81AMessageThatCannotBeDecodedIsTermedLoggedAndSkipped(array $options): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturnCallback(static function (array $encoded): Envelope {
            if (($encoded['body'] ?? null) === 'broken') {
                throw new MessageDecodingFailedException('Deserialized data is not a valid Symfony Messenger Envelope.');
            }

            return new Envelope(new \stdClass());
        });
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.7.1.0.0', payload: 'broken'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.8.2.0.0', payload: 'fine'),
        ]));
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::once())->method('term')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === '$JS.ACK.test-stream.client.1.7.1.0.0'))
            ->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new class (self::VALID_DSN, $options, $serializer, $logger) extends RuntimeTestableNatsTransport {
            protected function messengerPassesOnDecodeFailures(): bool
            {
                return false;
            }
        };
        $transport->setJetStreamContext($jetStream);
        $envelopes = array_values(iterator_to_array($transport->get(), false));

        self::assertCount(1, $envelopes, 'the message after it in the batch is received');
        self::assertSame('$JS.ACK.test-stream.client.1.8.2.0.0', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame([
            'A message on stream "test-stream", sequence 7 could not be decoded (Deserialized data is not a valid Symfony'
            . ' Messenger Envelope.) and TERMed.',
        ], $logger->messagesAt('error'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function modesThatReceiveAMessageThatCannotBeDecoded(): iterable
    {
        yield 'symfony mode' => [[]];
        yield 'retry_via=nak' => [self::NAK_RETRIES];
        yield 'nats mode' => [self::NATS_MODE];
    }

    /**
     * Before Messenger 8.1, without a logger, the message is still TERMed and the batch goes on.
     */
    public function testBeforeMessenger81AMessageThatCannotBeDecodedIsTermedWithoutALogger(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturnCallback(static function (array $encoded): Envelope {
            if (($encoded['body'] ?? null) === 'broken') {
                throw new MessageDecodingFailedException('not an envelope');
            }

            return new Envelope(new \stdClass());
        });
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: 'reply-broken', payload: 'broken'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: 'reply-fine', payload: 'fine'),
        ]));
        $jetStream->expects(self::once())->method('term')->willReturn(Future::complete());

        $transport = new class (self::VALID_DSN, [], $serializer) extends RuntimeTestableNatsTransport {
            protected function messengerPassesOnDecodeFailures(): bool
            {
                return false;
            }
        };
        $transport->setJetStreamContext($jetStream);
        $envelopes = array_values(iterator_to_array($transport->get(), false));

        self::assertCount(1, $envelopes);
        self::assertSame('reply-fine', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    /**
     * Before Messenger 8.1, a TERM that fails, as on a connection that dropped, is logged with the decode error, and
     * the worker still keeps running: the message comes back after ack_wait.
     */
    public function testBeforeMessenger81ATermThatFailsIsLoggedAndTheWorkerKeepsRunning(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willThrowException(new \RuntimeException('decode failed'));
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([new NatsMessage('test-topic', 1, 'reply-id', 'payload')]));
        $logger = new RecordingLogger();

        $transport = new class (self::VALID_DSN, [], $serializer, $logger) extends ThrowingFailureNatsTransport {
            protected function messengerPassesOnDecodeFailures(): bool
            {
                return false;
            }
        };
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], iterator_to_array($transport->get(), false));
        self::assertSame([
            'A message on stream "test-stream" could not be decoded (decode failed) and could not be TERMed (term transport'
            . ' failure), so it comes back after ack_wait.',
        ], $logger->messagesAt('error'));
    }

    /**
     * From Symfony Messenger 8.1 on, a message the serializer cannot decode goes on as the decoding failure, as with
     * Symfony's own serializers: an envelope whose message is the MessageDecodingFailedException, carrying what was
     * received and the serializer's exception, with its delivery token, and nothing settled yet. The worker handles
     * and fails it, Symfony's retry strategy and failure transport deal with it, and nats mode TERMs it when it is
     * settled.
     */
    public function testFromMessenger81AMessageThatCannotBeDecodedGoesOnAsTheDecodingFailure(): void
    {
        if (!(new \ReflectionClass(MessageDecodingFailedException::class))->hasMethod('wrap')) {
            self::markTestSkipped('Symfony Messenger before 8.1 has no MessageDecodingFailedException::wrap().');
        }

        $decodeError = new \RuntimeException('decode failed', 42);
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willThrowException($decodeError);
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.7.1.0.0', payload: 'broken'),
        ]));
        $jetStream->expects(self::never())->method('term');
        $jetStream->expects(self::never())->method('nak');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE, $serializer);
        $transport->setJetStreamContext($jetStream);
        $envelopes = array_values(iterator_to_array($transport->get(), false));

        self::assertCount(1, $envelopes);
        $failure = $envelopes[0]->getMessage();
        self::assertInstanceOf(MessageDecodingFailedException::class, $failure);
        self::assertSame('decode failed', $failure->getMessage());
        self::assertSame(42, $failure->getCode());
        self::assertSame($decodeError, $failure->getPrevious());
        self::assertSame(['body' => 'broken', 'headers' => []], $failure->encodedEnvelope);
        self::assertSame('$JS.ACK.test-stream.client.1.7.1.0.0', $envelopes[0]->last(TransportMessageIdStamp::class)?->getId());
    }

    /**
     * From Messenger 8.1 on, an exception whose code is not an int, as a PDOException's SQLSTATE, gives a decoding
     * failure with the code 0, as MessageDecodingFailedException takes an int.
     */
    public function testFromMessenger81ADecodeErrorWhoseCodeIsNotAnIntGivesTheCodeZero(): void
    {
        if (!(new \ReflectionClass(MessageDecodingFailedException::class))->hasMethod('wrap')) {
            self::markTestSkipped('Symfony Messenger before 8.1 has no MessageDecodingFailedException::wrap().');
        }

        $decodeError = new \PDOException('the payload store failed');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($decodeError, 'HY000');
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willThrowException($decodeError);
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([new NatsMessage('test-topic', 1, 'reply-id', 'payload')]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);
        $envelopes = array_values(iterator_to_array($transport->get(), false));

        self::assertCount(1, $envelopes);
        $failure = $envelopes[0]->getMessage();
        self::assertInstanceOf(MessageDecodingFailedException::class, $failure);
        self::assertSame(0, $failure->getCode());
        self::assertSame($decodeError, $failure->getPrevious());
    }

    /**
     * With retry_via=nak, the decoding failure of a redelivered message carries its earlier deliveries as its retry
     * count, as a decoded message does. Symfony Messenger 8.1.0 to 8.1.10 retry a failure a serializer threw (8.1.11
     * fails it as unrecoverable), and a NAK retry redelivers the same bytes, which fail again: without the count, every
     * failure would be Symfony's first, and max_retries would never end the retries.
     */
    public function testFromMessenger81TheDecodingFailureOfARedeliveryCarriesItsRetryCount(): void
    {
        if (!(new \ReflectionClass(MessageDecodingFailedException::class))->hasMethod('wrap')) {
            self::markTestSkipped('Symfony Messenger before 8.1 has no MessageDecodingFailedException::wrap().');
        }

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willThrowException(new MessageDecodingFailedException('not an envelope'));
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.3.7.3.0.0', payload: 'broken'),
        ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $serializer);
        $transport->setJetStreamContext($jetStream);
        $envelopes = array_values(iterator_to_array($transport->get(), false));

        self::assertCount(1, $envelopes);
        self::assertInstanceOf(MessageDecodingFailedException::class, $envelopes[0]->getMessage());
        self::assertSame(2, RedeliveryStamp::getRetryCountFromEnvelope($envelopes[0]));
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

        $serializer = $this->createStub(SerializerInterface::class);
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
        $jetStream = $this->createStub(JetStreamContext::class);
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

        $serializer = $this->createStub(SerializerInterface::class);
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

        $serializer = $this->createStub(SerializerInterface::class);
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

        // A wait that ignored connection_timeout would end with the watcher, at 5 s. Only far from that does the time say
        // anything, as this process can be paused.
        self::assertLessThan(4.0, (hrtime(true) - $start) / 1e9, 'gave up the PING at connection_timeout');
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
     * The NAK or TERM of a delivery whose handler failed checks the connection first, with one PING, though the
     * connection was used a moment ago: the server may have closed it while the handler ran, and a write into a
     * connection whose close the client has not read is lost (ReconnectModeTest shows it with the real client). The
     * order is checked: PING, then the acknowledgement. The ACK of a handled message after it pings nothing.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('failedDeliverySettlements')]
    public function testSettlingAFailedDeliveryChecksTheConnectionFirst(array $options, string $settle, string $frame): void
    {
        $calls = [];
        $jetStream = $this->createStub(JetStreamContext::class);
        foreach (['ack', 'nak', 'nakWithDelay', 'term'] as $method) {
            $jetStream->method($method)->willReturnCallback(static function () use (&$calls, $method): Future {
                $calls[] = $method;

                return Future::complete();
            });
        }

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturnCallback(static function () use (&$calls): Future {
            $calls[] = 'rtt';

            return Future::complete(0.001);
        });
        $client->expects(self::never())->method('disconnect');

        $transport = new ClockedNatsTransport(self::VALID_DSN, $options);
        $transport->setClient($client);
        $message = new \stdClass();
        $received = (new Envelope($message))->with(new TransportMessageIdStamp('reply-token'), new ReceivedStamp('nats'));
        // The connection is in use: an ACK that needs no check.
        $transport->ack($received);

        if ($settle === 'ack') {
            $transport->send($received->with(new RedeliveryStamp(1)));
            $transport->ack($received);
        } else {
            $transport->reject($received);
        }
        // The check is over: the next ACK pings nothing.
        $transport->ack($received);

        self::assertSame(['ack', 'rtt', $frame, 'ack'], $calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function failedDeliverySettlements(): iterable
    {
        yield 'reject(), symfony mode: TERM' => [[], 'reject', 'term'];
        yield 'reject(), nats mode: NAK' => [['retry_handler' => 'nats'], 'reject', 'nak'];
        yield 'reject(), nats mode with nak_delay' => [['retry_handler' => 'nats', 'nak_delay' => 1], 'reject', 'nakWithDelay'];
        yield 'ack() of a delivery whose retry copy was left out: NAK' => [['retry_handler' => 'nats'], 'ack', 'nak'];
    }

    /** ping_after_idle 0 turns this check off as well. */
    public function testPingAfterIdleZeroTurnsTheCheckBeforeSettlingAFailedDeliveryOff(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('nak')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats', 'ping_after_idle' => 0]);
        $transport->setClient($client);
        $transport->reject((new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token')));
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

        $serializer = $this->createStub(SerializerInterface::class);
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

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30, 'auto_setup' => true], $serializer);
        $transport->setClient($client);
        $transport->send(new Envelope(new \stdClass()));

        $transport->now += 31;
        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * The client can keep reporting a connection Open after its socket died - older clients after a failed
     * write ("The stream is not writable"), 2.10.0 after the server's fatal -ERR, 2.10.3 after 'maximum
     * subscriptions exceeded' when the server closes the connection right behind it - so an operation that
     * failed on the connection makes the next one check it with a PING first, and move to a new connection
     * when it goes unanswered, instead of failing the same way until the process restarts (#49).
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

        $serializer = $this->createStub(SerializerInterface::class);
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

    /**
     * A pull the server refused at the connection's subscription limit fails get() with Symfony's TransportException,
     * which ReceiverInterface::get() declares, naming the stream, the consumer and the limit, with the client's
     * SubscriptionLimitException as its previous one (#76). It still counts as a failure on the connection, since the
     * server sends the same error when it closes the connection right behind it: the next operation checks the
     * connection first, and keeps it when the PING is answered.
     */
    public function testPullRefusedAtTheSubscriptionLimitFailsWithATransportExceptionAndTheNextOperationChecksTheConnection(): void
    {
        $limit = new SubscriptionLimitException("Server sent error frame: 'maximum subscriptions exceeded'");
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error($limit),
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));
        $client->expects(self::never())->method('disconnect');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);

        try {
            iterator_to_array($transport->get());
            self::fail('expected get() to fail');
        } catch (TransportException $e) {
            self::assertSame(
                'Cannot pull from NATS stream "test-stream", consumer "client": the subscription limit was exceeded. '
                . 'This transport requires capacity for at least two subscriptions per connection. Check server, account and user limits.',
                $e->getMessage(),
            );
            self::assertSame(0, $e->getCode());
            self::assertSame($limit, $e->getPrevious());
        }

        // The client still reports Open, and no time has passed: the check comes from the refused pull.
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * Guard: any other client failure of a pull propagates as it is, not as a TransportException, and the next
     * operation checks the connection, as before #76.
     */
    public function testPullThatFailedOnTheConnectionOtherwisePropagatesAsItIs(): void
    {
        $lost = new ConnectionException('Connection is not open');
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error($lost),
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);

        try {
            iterator_to_array($transport->get());
            self::fail('expected get() to fail');
        } catch (\Throwable $e) {
            self::assertSame($lost, $e);
        }

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
     * A call that throws before it returns its Future, as a refused dial does, did not fail on a connection, so the
     * operation after the next dial does not check the connection first. awaitOnConnection() starts the call before
     * its try, as the argument expression did when it took the Future.
     */
    public function testARefusedDialDoesNotMakeTheOperationAfterTheNextDialCheckTheConnection(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(3))->method('ack')->willReturn(Future::complete());

        $refused = new ConnectionException('Connection to tcp://localhost:4222 failed');
        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::error($refused), Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);
        $state = ConnectionState::Closed;

        try {
            $transport->ack($envelope);
            self::fail('expected the dial to fail');
        } catch (ConnectionException $e) {
            self::assertSame($refused, $e);
        }

        $transport->ack($envelope);
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

    /**
     * A login the server refuses reads like an outage: 0, after one attempt. send() and get() raise the error.
     */
    public function testMessageCountOfALoginTheServerRefusesDialsOnceAndReturnsZero(): void
    {
        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::error(new AuthenticationException("Server rejected authentication during connect: 'Authorization Violation'"))]);
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
        $serializer = $this->createStub(SerializerInterface::class);
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
        $jetStream = $this->createStub(JetStreamContext::class);
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
        $serializer = $this->createStub(SerializerInterface::class);
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
        $serializer = $this->createStub(SerializerInterface::class);
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE, $serializer);
        $transport->setJetStreamContext($jetStream);

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new \RuntimeException('always fails');
            }],
        ]))]);
        $locator = static fn (object $service): ContainerInterface => self::singleServiceLocator($service);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            $locator($transport),
            $locator(new MultiplierRetryStrategy(3, 1000, 2)),
        ));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    /**
     * The same through Symfony's Worker with two messages in one batch, the first failing and the second handled:
     * only the delivery whose retry copy was left out is NAKed, and the other is acknowledged as usual (#90).
     * Holds on every Symfony Messenger release: the worker settles the failed one with ack() from 7.4.22 and
     * 8.1.10 on, and with reject() before.
     */
    public function testWorkerInNatsModeNaksOnlyTheDeliveryItSentForRetry(): void
    {
        $failing = '$JS.ACK.test-stream.client.1.1.1.0.0';
        $handled = '$JS.ACK.test-stream.client.1.2.2.0.0';
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: $failing, payload: 'fails'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: $handled, payload: 'works'),
        ]));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::never())->method('term');
        $jetStream->expects(self::once())->method('nak')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === $failing))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('ack')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === $handled))
            ->willReturn(Future::complete());

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturnCallback(static function (array $encoded): Envelope {
            $message = new \stdClass();
            $message->fails = $encoded['body'] === 'fails';

            return new Envelope($message);
        });

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE, $serializer);
        $transport->setJetStreamContext($jetStream);

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (\stdClass $message): void {
                if ($message->fails === true) {
                    throw new \RuntimeException('fails once');
                }
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator(new MultiplierRetryStrategy(3, 1000, 2)),
        ));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(2));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    /**
     * In nats mode the ack() Symfony Messenger 7.4.22, 8.1.10 and later send for a delivery whose retry copy
     * send() left out NAKs it, with nak_delay when one is set and never as an ACK, ack_sync or not: an ACK
     * dropped the message for good (#90).
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('natsModeOptions')]
    public function testAckNaksADeliveryWhoseRetryCopyNatsModeLeftOut(array $options, string $nakMethod): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::never())->method('ack');
        $jetStream->expects(self::never())->method('ackSync');
        $jetStream->expects(self::once())->method($nakMethod)
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === 'delivery-1'))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE + $options);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(1)));
        $transport->ack($failed);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function natsModeOptions(): iterable
    {
        yield 'defaults' => [[], 'nak'];
        yield 'nak_delay' => [['nak_delay' => 5], 'nakWithDelay'];
        yield 'ack_sync' => [['ack_sync' => true], 'nak'];
    }

    /**
     * A delivery sent for retry is settled once: the ack() of newer Symfony Messenger releases or the reject() of
     * older ones NAKs it and forgets it, so a later ack() of that envelope is an ACK again.
     */
    #[DataProvider('settlements')]
    public function testADeliverySentForRetryIsNakedOnce(string $settlement): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('nak')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('ack')->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);

        $transport->send($failed->with(new RedeliveryStamp(1)));
        $transport->{$settlement}($failed);
        $transport->ack($failed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function settlements(): iterable
    {
        yield 'ack (Messenger 7.4.22, 8.1.10 and later)' => ['ack'];
        yield 'reject (earlier releases)' => ['reject'];
    }

    /**
     * Only the delivery the retry copy belongs to is NAKed: another message, or another delivery of the same
     * message, is acknowledged as usual.
     */
    public function testAckNaksOnlyTheDeliveryTheRetryCopyBelongsTo(): void
    {
        $settled = [];
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('ack')->willReturnCallback(static function (NatsMessage $message) use (&$settled): Future {
            $settled[] = 'ack ' . $message->replyTo;

            return Future::complete();
        });
        $jetStream->method('nak')->willReturnCallback(static function (NatsMessage $message) use (&$settled): Future {
            $settled[] = 'nak ' . $message->replyTo;

            return Future::complete();
        });

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE);
        $transport->setJetStreamContext($jetStream);
        $retried = new \stdClass();
        $failed = new Envelope($retried, [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);
        $transport->send($failed->with(new RedeliveryStamp(1)));

        $transport->ack(new Envelope(new \stdClass(), [new TransportMessageIdStamp('delivery-1')]));
        $transport->ack(new Envelope($retried, [new TransportMessageIdStamp('delivery-2')]));
        $transport->ack($failed);

        self::assertSame(['ack delivery-1', 'ack delivery-2', 'nak delivery-1'], $settled);
    }

    /**
     * Each delivery sent for retry is remembered until it is settled, however many are waiting at once.
     */
    public function testEveryDeliverySentForRetryIsNakedWhenSettledLater(): void
    {
        $naked = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('ack');
        $jetStream->method('nak')->willReturnCallback(static function (NatsMessage $message) use (&$naked): Future {
            $naked[] = $message->replyTo;

            return Future::complete();
        });

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE);
        $transport->setJetStreamContext($jetStream);
        $first = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);
        $second = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-2')]);

        $transport->send($first->with(new RedeliveryStamp(1)));
        $transport->send($second->with(new RedeliveryStamp(1)));
        $transport->ack($first);
        $transport->ack($second);

        self::assertSame(['delivery-1', 'delivery-2'], $naked);
    }

    /**
     * With the default retry_handler=symfony the retry copy is published, so the original is acknowledged as
     * Symfony Messenger asks, not NAKed.
     */
    public function testSymfonyModeAcksADeliveryItPublishedTheRetryCopyOf(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('ack')->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('nak');
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $serializer);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);

        $transport->send($failed->with(new RedeliveryStamp(1)));
        $transport->ack($failed);
    }

    /**
     * In nats mode the first retry copy send() leaves out is logged, through the transport's logger, as a warning
     * that Symfony's retry strategy is on, and only the first: here through Symfony's Worker and retry listener with
     * FrameworkBundle's default strategy (3 retries) over a batch of two failing messages, whose two copies are both
     * left out (#94).
     */
    public function testNatsModeWarnsOnceThatSymfonysRetryStrategyIsOn(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'first'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.2.2.0.0', payload: 'second'),
        ]));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::exactly(2))->method('nak')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE, $this->decodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        self::runWorkerOverFailingMessages($transport, new MultiplierRetryStrategy(3, 1000, 2), 2);

        self::assertSame([[
            'level' => 'warning',
            'message' => 'retry_handler=nats on stream "test-stream", subject "test-topic": Symfony\'s retry strategy retried a message that NATS redelivers itself. Set max_retries: 0 on this transport (Symfony still retries a RecoverableExceptionInterface), or have NATS carry out Symfony\'s retries with retry_handler: symfony and retry_via: nak (README, "Retry Handler Behavior").',
        ]], $logger->records);
    }

    /**
     * With max_retries: 0, as the README asks for in nats mode, Symfony's Worker sends no retry copy and rejects the
     * failed delivery, which is NAKed, and nothing is logged (#94).
     */
    public function testNatsModeWithoutSymfonysRetriesLogsNoWarning(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'encoded'),
        ]));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::once())->method('nak')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE, $this->decodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        self::runWorkerOverFailingMessages($transport, new MultiplierRetryStrategy(0), 1);

        self::assertSame([], $logger->records);
    }

    /**
     * No warning that Symfony's retry strategy is on is logged when send() leaves out no retry copy: in symfony mode,
     * where Symfony's retry copy is published, and in nats mode for a copy for the failure transport, a message not
     * received in this process and a plain dispatch (#94). A retry copy published without its delay logs a warning of
     * its own ({@see testARetryPublishedWithoutItsDelayIsLoggedOnce()}).
     *
     * @param array<string, mixed> $options
     * @param list<StampInterface> $stamps
     */
    #[DataProvider('sendsThatLeaveOutNoRetryCopy')]
    public function testNoWarningWhenSendLeavesOutNoRetryCopy(array $options, array $stamps): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options, $serializer, $logger);
        $transport->setJetStreamContext($jetStream);
        $transport->send(new Envelope(new \stdClass(), $stamps));

        self::assertSame([], array_values(array_filter(
            $logger->messagesAt('warning'),
            static fn (string $message): bool => str_contains($message, 'retry strategy retried a message that NATS redelivers itself'),
        )));
    }

    /**
     * With retry_via=copy and no scheduled_messages, Symfony's retry is published at once, without the delay its
     * strategy computed: the retries of a message that keeps failing all run within milliseconds. The transport says
     * so once per transport object, at the first such retry, with the delay it dropped.
     */
    public function testARetryPublishedWithoutItsDelayIsLoggedOnce(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('publish')->with('test-topic')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [], $this->encodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        foreach ([[1000, 1], [2000, 2]] as [$delayMs, $retry]) {
            $transport->send(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-' . $retry), new DelayStamp($delayMs), new RedeliveryStamp($retry)]));
        }

        self::assertSame([
            'retry_via=copy on stream "test-stream", subject "test-topic": Symfony\'s retry was published at once, without'
            . ' its delay of 1000 ms: a copy waits only with scheduled_messages. Set retry_via: nak to have NATS wait, or'
            . ' scheduled_messages: true (NATS 2.12+, not on an interest stream).',
        ], $logger->messagesAt('warning'));
    }

    /**
     * Nothing is logged for a retry that waits as a schedule, a retry without a delay, a failure transport's copy and
     * an application's own delayed message, which carries no retry count.
     *
     * @param array<string, mixed> $options
     * @param list<StampInterface> $stamps
     */
    #[DataProvider('sendsThatKeepTheirDelayOrHaveNone')]
    public function testASendThatKeepsItsDelayOrHasNoneIsNotLogged(array $options, array $stamps): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options, $this->encodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        $transport->send(new Envelope(new \stdClass(), $stamps));

        self::assertSame([], $logger->records);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<StampInterface>}>
     */
    public static function sendsThatKeepTheirDelayOrHaveNone(): iterable
    {
        yield 'a retry scheduled with scheduled_messages' => [['scheduled_messages' => true], [new ReceivedStamp('async'), new DelayStamp(1000), new RedeliveryStamp(1)]];
        yield 'a retry without a delay' => [[], [new ReceivedStamp('async'), new DelayStamp(0), new RedeliveryStamp(1)]];
        yield 'a failure transport\'s copy' => [[], [new ReceivedStamp('async'), new DelayStamp(0), new RedeliveryStamp(0)]];
        yield 'an application\'s own delayed message' => [[], [new DelayStamp(5000)]];
        yield 'a received message sent again with a delay and no retry count' => [[], [new ReceivedStamp('async'), new DelayStamp(1000), new RedeliveryStamp(0)]];
        yield 'a delayed message with a retry count, not received here' => [[], [new DelayStamp(1000), new RedeliveryStamp(1)]];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<StampInterface>}>
     */
    public static function sendsThatLeaveOutNoRetryCopy(): iterable
    {
        yield 'symfony mode, Symfony\'s retry copy' => [[], [new ReceivedStamp('async'), new DelayStamp(1000), new RedeliveryStamp(1)]];
        yield 'retry_via=copy, Symfony\'s retry copy' => [
            ['retry_via' => 'copy'],
            [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1'), new DelayStamp(1000), new RedeliveryStamp(1)],
        ];
        // No NAK could carry a retry without the delivery it belongs to, so the copy is published.
        yield 'retry_via=nak, a retry copy without its delivery token' => [
            ['retry_via' => 'nak'],
            [new ReceivedStamp('async'), new DelayStamp(1000), new RedeliveryStamp(1)],
        ];
        foreach (self::envelopesNatsModeStillPublishes() as $name => [$stamps]) {
            yield 'retry_via=nak, ' . $name => [['retry_via' => 'nak'], $stamps];
        }
        foreach (self::envelopesNatsModeStillPublishes() as $name => [$stamps]) {
            yield 'nats mode, ' . $name => [['retry_handler' => 'nats'], $stamps];
        }
    }

    /**
     * Once per transport object: each transport in nats mode warns about its own stream and subject the first time
     * it leaves out a retry copy, whether the copy carries a delivery token or not, and stays quiet after that.
     */
    public function testEachTransportWarnsOnceAboutItsOwnStreamAndSubject(): void
    {
        $logger = new RecordingLogger();
        $first = new RuntimeTestableNatsTransport(self::VALID_DSN, ['retry_handler' => 'nats'], new PhpSerializer(), $logger);
        $second = new RuntimeTestableNatsTransport('nats://localhost:4222/orders/orders.created', ['retry_handler' => 'nats'], new PhpSerializer(), $logger);
        $retry = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new RedeliveryStamp(1)]);

        $first->send($retry);
        $second->send($retry->with(new TransportMessageIdStamp('delivery-1')));
        $first->send($retry->with(new TransportMessageIdStamp('delivery-2')));
        $second->send($retry->with(new RedeliveryStamp(2)));

        self::assertSame([
            'retry_handler=nats on stream "test-stream", subject "test-topic": Symfony\'s retry strategy retried a message that NATS redelivers itself. Set max_retries: 0 on this transport (Symfony still retries a RecoverableExceptionInterface), or have NATS carry out Symfony\'s retries with retry_handler: symfony and retry_via: nak (README, "Retry Handler Behavior").',
            'retry_handler=nats on stream "orders", subject "orders.created": Symfony\'s retry strategy retried a message that NATS redelivers itself. Set max_retries: 0 on this transport (Symfony still retries a RecoverableExceptionInterface), or have NATS carry out Symfony\'s retries with retry_handler: symfony and retry_via: nak (README, "Retry Handler Behavior").',
        ], $logger->messagesAt('warning'));
        self::assertCount(2, $logger->records);
    }

    /** A serializer that decodes every payload into an envelope of its own. */
    private function decodingSerializer(): SerializerInterface
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturnCallback(static fn (): Envelope => new Envelope(new \stdClass()));

        return $serializer;
    }

    /**
     * Runs Symfony's Worker, with its retry listener and the given retry strategy, over the transport until it has
     * received the given number of messages, the handler failing every one of them.
     */
    private static function runWorkerOverFailingMessages(NatsTransport $transport, MultiplierRetryStrategy $strategy, int $messages): void
    {
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new \RuntimeException('always fails');
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator($strategy),
        ));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($messages));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    /**
     * A service locator that answers every id with the one service, for Symfony's retry listener.
     */
    private static function singleServiceLocator(object $service): ContainerInterface
    {
        return new class ($service) implements ContainerInterface {
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

    /**
     * What nats-server 2.10 answers to a consumer request while it moves the consumer after a change of
     * stream_replicas, and the consumer has no leader for a moment.
     */
    private static function clusterNotAvailable(): JetStreamException
    {
        return new JetStreamException('JetStream system temporarily unavailable', 503, null, 10008);
    }

    private static function liveConsumer(string $replayPolicy): ConsumerInfo
    {
        return new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => [
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
                'filter_subject' => 'test-topic',
                'replay_policy' => $replayPolicy,
            ]],
        );
    }

    public function testSetupAsksForTheConsumerAgainWhileTheClusterIsTemporarilyUnavailable(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->with('test-stream', 'client')
            ->willReturnOnConsecutiveCalls(
                Future::error(self::clusterNotAvailable()),
                Future::complete(self::liveConsumer('original')),
            );
        // The live consumer's replay policy, which only the second answer carried, is the one sent.
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static fn (ConsumerConfiguration $configuration): bool => ($configuration->toArray()['replay_policy'] ?? null) === 'original'))
            ->willReturn(Future::complete(self::liveConsumer('original')));

        $transport = new PausingNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([0.1], $transport->pauses);
    }

    public function testSetupCreatesTheConsumerAgainWhileTheClusterIsTemporarilyUnavailable(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404, null, 10014)));
        $jetStream->expects(self::exactly(3))
            ->method('addConsumer')
            ->with('test-stream', self::isInstanceOf(ConsumerConfiguration::class))
            ->willReturnOnConsecutiveCalls(
                Future::error(self::clusterNotAvailable()),
                Future::error(self::clusterNotAvailable()),
                Future::complete(self::liveConsumer('instant')),
            );

        $transport = new PausingNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([0.1, 0.1], $transport->pauses);
    }

    public function testSetupGivesUpOnATemporarilyUnavailableClusterAfterFiveSeconds(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        // Asked at 0, 1, 2, 3, 4 and 5 seconds; the answer at 5 seconds is the last one.
        $jetStream->expects(self::exactly(6))
            ->method('getConsumer')
            ->willReturnCallback(static fn (): Future => Future::error(self::clusterNotAvailable()));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new PausingNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);
        $transport->secondsPerPause = 1.0;

        try {
            $transport->setup();
            self::fail('setup() should have failed');
        } catch (\RuntimeException $exception) {
            self::assertSame("Failed to setup NATS stream 'test-stream': JetStream system temporarily unavailable", $exception->getMessage());
            self::assertInstanceOf(JetStreamException::class, $exception->getPrevious());
        }
        self::assertCount(5, $transport->pauses);
    }

    public function testSetupDoesNotAskForTheConsumerAgainAfterAnotherJetStreamError(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('jetstream not enabled for account', 503, null, 10039)));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new PausingNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->setup();
            self::fail('setup() should have failed');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('jetstream not enabled for account', $exception->getMessage());
        }
        self::assertSame([], $transport->pauses);
    }

    public function testSetupWaitsBeforeAskingTheTemporarilyUnavailableClusterAgain(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(self::clusterNotAvailable()),
                Future::complete(self::liveConsumer('instant')),
            );
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete(self::liveConsumer('instant')));

        // The real pause and clock.
        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $start = hrtime(true);
        $transport->setup();

        // At least half of the 100 ms: without the pause, setup() returns within a few milliseconds. Not the whole
        // 100 ms: with ext-event, Revolt's loop runs on libevent, whose timers read a coarse clock, and a 100 ms
        // delay can end a little under 100 ms by hrtime() (measured: 0.0992 s).
        self::assertGreaterThanOrEqual(0.05, (hrtime(true) - $start) / 1e9);
    }

    /**
     * What a consumer request can get for a moment while NATS moves the consumer after a change of stream_replicas:
     * the stream setup() has just updated, reported as not found (measured on nats-server 2.10.29).
     */
    private static function streamNotFound(): JetStreamException
    {
        return new JetStreamException('stream not found', 404, null, 10059);
    }

    public function testSetupAsksForTheConsumerAgainWhileTheMovedStreamIsReportedAsNotFound(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(self::streamNotFound()),
                Future::complete(self::liveConsumer('original')),
            );
        // Not read as "no consumer": the configured policy (instant) would differ from the live consumer's.
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static fn (ConsumerConfiguration $configuration): bool => ($configuration->toArray()['replay_policy'] ?? null) === 'original'))
            ->willReturn(Future::complete(self::liveConsumer('original')));

        $transport = new PausingNatsTransport(self::VALID_DSN, ['replay_policy' => 'instant']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([0.1], $transport->pauses);
    }

    public function testSetupCreatesTheConsumerAgainWhileTheMovedStreamIsReportedAsNotFound(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404, null, 10014)));
        $jetStream->expects(self::exactly(2))
            ->method('addConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(self::streamNotFound()),
                Future::complete(self::liveConsumer('instant')),
            );

        $transport = new PausingNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([0.1], $transport->pauses);
    }

    public function testSetupGivesUpOnAStreamStillReportedAsNotFoundAfterFiveSeconds(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        // Asked at 0, 1, 2, 3, 4 and 5 seconds, as for 10008.
        $jetStream->expects(self::exactly(6))
            ->method('getConsumer')
            ->willReturnCallback(static fn (): Future => Future::error(self::streamNotFound()));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new PausingNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);
        $transport->secondsPerPause = 1.0;

        try {
            $transport->setup();
            self::fail('setup() should have failed');
        } catch (\RuntimeException $exception) {
            self::assertSame("Failed to setup NATS stream 'test-stream': stream not found", $exception->getMessage());
            self::assertSame(10059, $exception->getPrevious() instanceof JetStreamException ? $exception->getPrevious()->getErrCode() : null);
        }
        self::assertCount(5, $transport->pauses);
    }

    /**
     * Guard: a consumer that is not there (err_code 10014) is still read as no consumer at once, and the configured
     * replay policy goes to the new one.
     */
    public function testSetupReadsAMissingConsumerAsNoConsumerWithoutAskingAgain(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404, null, 10014)));
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static fn (ConsumerConfiguration $configuration): bool => ($configuration->toArray()['replay_policy'] ?? null) === 'original'))
            ->willReturn(Future::complete(self::liveConsumer('original')));

        $transport = new PausingNatsTransport(self::VALID_DSN, ['replay_policy' => 'original']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([], $transport->pauses);
    }

    public function testSetupAsksForTheConsumerOnceMoreAfterARequestTheClusterDidNotAnswer(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(new TimeoutException('Request timed out')),
                Future::complete(self::liveConsumer('original')),
            );
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->with('test-stream', self::callback(static fn (ConsumerConfiguration $configuration): bool => ($configuration->toArray()['replay_policy'] ?? null) === 'original'))
            ->willReturn(Future::complete(self::liveConsumer('original')));

        // ping_after_idle 0: no connection check after the failed request, which this test is not about.
        $transport = new PausingNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setJetStreamContext($jetStream);
        // The unanswered request took request_timeout, longer than the 5 s the codes above are asked again for.
        $transport->secondsPerPause = 10.0;

        $transport->setup();

        self::assertSame([0.1], $transport->pauses);
    }

    public function testSetupCreatesTheConsumerOnceMoreAfterARequestTheClusterDidNotAnswer(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())
            ->method('getConsumer')
            ->willReturn(Future::error(new JetStreamException('consumer not found', 404, null, 10014)));
        $jetStream->expects(self::exactly(2))
            ->method('addConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(new TimeoutException('Request timed out')),
                Future::complete(self::liveConsumer('instant')),
            );

        $transport = new PausingNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([0.1], $transport->pauses);
    }

    public function testSetupGivesUpOnASecondConsumerRequestTheClusterDidNotAnswer(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->willReturnCallback(static fn (): Future => Future::error(new TimeoutException('Request timed out')));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new PausingNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->setup();
            self::fail('setup() should have failed');
        } catch (\RuntimeException $exception) {
            self::assertSame("Failed to setup NATS stream 'test-stream': Request timed out", $exception->getMessage());
            self::assertInstanceOf(TimeoutException::class, $exception->getPrevious());
        }
        self::assertSame([0.1], $transport->pauses);
    }

    /**
     * An unanswered request is asked once more for each of the two consumer requests: a lookup that got its answer
     * the second time leaves the create its own second try.
     */
    public function testEachConsumerRequestGetsItsOwnSecondTryAfterAnUnansweredOne(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(new TimeoutException('Request timed out')),
                Future::error(new JetStreamException('consumer not found', 404, null, 10014)),
            );
        $jetStream->expects(self::exactly(2))
            ->method('addConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(new TimeoutException('Request timed out')),
                Future::complete(self::liveConsumer('instant')),
            );

        $transport = new PausingNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertSame([0.1, 0.1], $transport->pauses);
    }

    /** After an unanswered request, a 10008 past the 5 seconds still ends the setup. */
    public function testSetupGivesUpOnATemporarilyUnavailableClusterAfterAnUnansweredRequestPastFiveSeconds(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))
            ->method('getConsumer')
            ->willReturnOnConsecutiveCalls(
                Future::error(new TimeoutException('Request timed out')),
                Future::error(self::clusterNotAvailable()),
            );
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new PausingNatsTransport(self::VALID_DSN, ['ping_after_idle' => 0]);
        $transport->setJetStreamContext($jetStream);
        $transport->secondsPerPause = 10.0;

        try {
            $transport->setup();
            self::fail('setup() should have failed');
        } catch (\RuntimeException $exception) {
            self::assertSame("Failed to setup NATS stream 'test-stream': JetStream system temporarily unavailable", $exception->getMessage());
        }
        self::assertSame([0.1], $transport->pauses);
    }

    public function testAutoSetupProvisionsOnFirstSendOnce(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
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
        $serializer = $this->createStub(SerializerInterface::class);
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
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        // Recording the order matters: provisioning that runs *after* the publish would still satisfy
        // simple call-count expectations while defeating the entire point of auto_setup.
        $calls = [];
        $jetStream = $this->createStub(JetStreamContext::class);
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
        $jetStream = $this->createStub(JetStreamContext::class);
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
        $serializer = $this->createStub(SerializerInterface::class);
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
     * The second pull after auto_setup provisioned the consumer again gets the same treatment as the first (#76): a
     * refusal at the subscription limit fails get() with the TransportException naming the limit.
     */
    public function testAutoSetupRetriedPullRefusedAtTheSubscriptionLimitFailsWithATransportException(): void
    {
        $consumerInfo = new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );
        $limit = new SubscriptionLimitException("Server sent error frame: 'maximum subscriptions exceeded'");

        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($consumerInfo));
        $jetStream->expects(self::exactly(2))
            ->method('fetchBatch')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('consumer not found', 503)),
                Future::error($limit),
            );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        try {
            iterator_to_array($transport->get());
            self::fail('expected get() to fail');
        } catch (TransportException $e) {
            self::assertSame(
                'Cannot pull from NATS stream "test-stream", consumer "client": the subscription limit was exceeded. '
                . 'This transport requires capacity for at least two subscriptions per connection. Check server, account and user limits.',
                $e->getMessage(),
            );
            self::assertSame($limit, $e->getPrevious());
        }
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

    /**
     * NATS before 2.12 has no message schedules, and never rejects the stream's allow_msg_schedules setting: it
     * ignores it. So the transport reads the version the server gave when the connection opened, and refuses the
     * setup itself, before it creates anything.
     */
    #[DataProvider('serversWithoutSchedules')]
    public function testSetupRefusesScheduledMessagesOnAServerWithoutSchedules(string $version): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('addStream');
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setClient($this->clientOfAServer($version));
        $transport->setJetStreamContext($jetStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The 'scheduled_messages' option requires NATS Server >= 2.12, but the connected server reports $version. Disable scheduled_messages or upgrade NATS.");

        $transport->setup();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serversWithoutSchedules(): iterable
    {
        yield 'NATS 2.9' => ['2.9.25'];
        yield 'NATS 2.10' => ['2.10.29'];
        yield 'NATS 2.11, the last release without schedules' => ['2.11.17'];
        yield 'a version with a leading v' => ['v2.11.0'];
    }

    /**
     * From 2.12 on the server schedules messages and the setup goes on. It also goes on when the server gave no
     * version, or one the transport cannot read: that is left for the server to decide.
     */
    #[DataProvider('serversThatMaySchedule')]
    public function testSetupWithScheduledMessagesGoesOnWhenTheServerMaySchedule(?string $version): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')
            ->with(self::callback(static fn (StreamConfiguration $config): bool => ($config->toArray()['allow_msg_schedules'] ?? false) === true))
            ->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setClient($this->clientOfAServer($version));
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function serversThatMaySchedule(): iterable
    {
        yield 'NATS 2.12, the first release with schedules' => ['2.12.0'];
        yield 'a release candidate of 2.12' => ['2.12.0-RC.1'];
        yield 'NATS 2.15' => ['2.15.2'];
        yield 'NATS 3' => ['3.0.0'];
        yield 'a version with a leading v' => ['v2.12.4'];
        yield 'a version the transport cannot read' => ['nightly'];
        yield 'a version that starts with something else' => ['build-2.10.3'];
        yield 'no server info' => [null];
    }

    /**
     * Without scheduled_messages the server's version plays no part: a setup on an older server goes on.
     */
    public function testSetupWithoutScheduledMessagesDoesNotLookAtTheServersVersion(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        )));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setClient($this->clientOfAServer('2.10.29'));
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * A delayed message is not published to a server without schedules, which would store it on its delayed subject
     * and never deliver it: send() throws instead, also when setup() never ran in this process (auto_setup off).
     */
    #[DataProvider('serversWithoutSchedules')]
    public function testSendRefusesToScheduleOnAServerWithoutSchedules(string $version): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setClient($this->clientOfAServer($version));
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));
            self::fail('A delayed message was sent to a server that cannot schedule it.');
        } catch (TransportException $refusal) {
            self::assertSame(
                "The 'scheduled_messages' option requires NATS Server >= 2.12, but the connected server reports $version. Disable scheduled_messages or upgrade NATS."
                . ' The delayed message was not sent: this server would store it and never deliver it.',
                $refusal->getMessage(),
            );
            self::assertInstanceOf(UnsupportedFeatureException::class, $refusal->getPrevious());
            self::assertSame(0, $refusal->getCode());
        }
    }

    /**
     * What the refusal leaves alone on a server without schedules: a message without a delay, or with a delay of 0,
     * is published as always, and so is a delayed one when scheduled_messages is off, where the delay is ignored.
     *
     * @param array<string, mixed> $options
     * @param list<StampInterface> $stamps
     */
    #[DataProvider('sendsThatScheduleNothing')]
    public function testSendWithoutAScheduleDoesNotLookAtTheServersVersion(array $options, array $stamps): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->with('test-topic', 'encoded')->willReturn(Future::complete());
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options, $serializer);
        $transport->setClient($this->clientOfAServer('2.10.29'));
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass(), $stamps));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<StampInterface>}>
     */
    public static function sendsThatScheduleNothing(): iterable
    {
        yield 'scheduled_messages, no delay' => [['scheduled_messages' => true], []];
        yield 'scheduled_messages, a delay of 0' => [['scheduled_messages' => true], [new DelayStamp(0)]];
        yield 'a delay without scheduled_messages' => [[], [new DelayStamp(5000)]];
    }

    /**
     * A delayed message goes out with its schedule to a server from 2.12 on, and to one whose version is unknown.
     */
    #[DataProvider('serversThatMaySchedule')]
    public function testSendSchedulesOnAServerThatMaySchedule(?string $version): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')
            ->with(self::stringStartsWith('test-topic.delayed.'), 'encoded', self::arrayHasKey('Nats-Schedule'))
            ->willReturn(Future::complete());
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setClient($this->clientOfAServer($version));
        $transport->setJetStreamContext($jetStream);

        $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));
    }

    /**
     * The server gives its version when the connection opens. So a transport that has not connected yet connects
     * before it looks: the first delayed send of a process (auto_setup off, nothing sent before) is refused too.
     */
    public function testTheFirstDelayedSendConnectsBeforeItLooksAtTheServersVersion(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setClient($this->clientThatLearnsItsServersVersionOnConnect('2.10.29', $jetStream));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage("The 'scheduled_messages' option requires NATS Server >= 2.12, but the connected server reports 2.10.29.");

        $transport->send(new Envelope(new \stdClass(), [new DelayStamp(5000)]));
    }

    /**
     * The same for a setup that is the first thing a process does, as `messenger:setup-transports` is.
     */
    public function testSetupConnectsBeforeItLooksAtTheServersVersion(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('addStream');

        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setClient($this->clientThatLearnsItsServersVersionOnConnect('2.10.29', $jetStream));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("The 'scheduled_messages' option requires NATS Server >= 2.12, but the connected server reports 2.10.29.");

        $transport->setup();
    }

    /** A client that has no server info until connect() ran, as the real one. */
    private function clientThatLearnsItsServersVersionOnConnect(string $version, JetStreamContext $jetStream): NatsClient
    {
        $connected = false;
        $client = $this->createMock(NatsClient::class);
        $client->expects(self::once())->method('connect')->willReturnCallback(static function () use (&$connected): Future {
            $connected = true;

            return Future::complete();
        });
        $client->method('jetStream')->willReturn($jetStream);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('serverInfo')->willReturnCallback(static function () use (&$connected, $version): ?ServerInfo {
            return $connected ? new ServerInfo('SERVERID', 'nats-0', $version, true, 1048576, true) : null;
        });

        return $client;
    }

    /** A client connected to a server of that version; null for one that has given no server info. */
    private function clientOfAServer(?string $version): NatsClient
    {
        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('serverInfo')->willReturn($version === null ? null : new ServerInfo('SERVERID', 'nats-0', $version, true, 1048576, true));

        return $client;
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
        $jetStream->expects(self::never())->method('publish');
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

    /**
     * A delivery time on a whole second is that second: only a fraction of a second is rounded up. (The real clock
     * almost never gives a whole second, so the transport is given one.)
     */
    public function testSendDelayedMessageDueOnAWholeSecondIsScheduledForThatSecond(): void
    {
        $capturedHeaders = $this->sendDelayedMessageDueAt('2026-11-02T12:00:05.000000+00:00', 5000, $delays);

        self::assertSame('@at 2026-11-02T12:00:05Z', $capturedHeaders['Nats-Schedule'] ?? null);
        self::assertSame([5000], $delays);
    }

    public function testSendDelayedMessageDueWithinASecondIsScheduledForTheNextOne(): void
    {
        $capturedHeaders = $this->sendDelayedMessageDueAt('2026-11-02T12:00:05.000001+00:00', 1500, $delays);

        self::assertSame('@at 2026-11-02T12:00:06Z', $capturedHeaders['Nats-Schedule'] ?? null);
        self::assertSame([1500], $delays);
    }

    /**
     * Sends a message with a DelayStamp of $delayMs through a transport whose delivery time is $dueAt, and returns
     * the headers it was published with.
     *
     * @param list<int>|null $delays Set to the delays the transport asked the delivery time for.
     * @return array<string, string>
     */
    private function sendDelayedMessageDueAt(string $dueAt, int $delayMs, ?array &$delays): array
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded-payload']);

        $capturedHeaders = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('publish')->willReturnCallback(
            function (string $subject, string $payload, array $headers) use (&$capturedHeaders): Future {
                $capturedHeaders = $headers;

                return Future::complete();
            }
        );

        $transport = new DeliveryTimeNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], $serializer);
        $transport->setJetStreamContext($jetStream);
        $transport->dueAt = new \DateTimeImmutable($dueAt);

        $transport->send(new Envelope(new \stdClass(), [new DelayStamp($delayMs)]));
        $delays = $transport->delays;

        return $capturedHeaders;
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
        $serializer = $this->createStub(SerializerInterface::class);
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

    /**
     * In nats mode with max_deliver, setup() warns once when the stream is one where NATS deletes a message that
     * reached max_deliver, the only record left of a message that kept failing there.
     *
     * @param array<string, mixed> $stream
     */
    #[DataProvider('streamsNatsModeLosesStoppedMessagesIn')]
    public function testSetupInNatsModeWarnsAboutAStreamWhereNatsDeletesWhatMaxDeliverStops(array $stream, string $version, string $when): void
    {
        $logger = new RecordingLogger();
        $this->setupWithTheStream(self::NATS_MODE + ['max_deliver' => 3], $stream, $version, $logger);

        self::assertSame([sprintf(
            'retry_handler=nats on stream "test-stream": NATS %s deletes a message that reached max_deliver from %s, so a'
            . ' message that keeps failing leaves nothing behind but the MAX_DELIVERIES advisory. Use retry_handler:'
            . ' symfony and retry_via: nak with a failure transport, or a stream with limits or work-queue retention'
            . ' (README, "Retry Handler Behavior").',
            $version,
            $when,
        )], $logger->messagesAt('warning'));
    }

    /**
     * A server whose version the client does not know is taken to be one that deletes the message from an interest
     * stream at a restart, and the warning says the version is unknown.
     */
    public function testSetupInNatsModeWarnsWhenTheServersVersionIsUnknown(): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete(StreamInfo::fromArray(['config' => ['name' => 'test-stream', 'subjects' => ['test-topic'], 'retention' => 'interest']])));
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('serverInfo')->willReturn(null);
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE + ['max_deliver' => 3], null, $logger);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);
        $transport->setup();

        self::assertCount(1, $logger->messagesAt('warning'));
        self::assertStringStartsWith('retry_handler=nats on stream "test-stream": NATS of an unknown version deletes a message that reached max_deliver from a stream with interest retention, at a restart of the server,', $logger->messagesAt('warning')[0]);
    }

    /**
     * Without a logger the warning goes nowhere, and setup() still works.
     */
    public function testSetupInNatsModeWithoutALoggerStillSetsUpAStreamThatLosesWhatMaxDeliverStops(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete(StreamInfo::fromArray(['config' => ['name' => 'test-stream', 'subjects' => ['test-topic'], 'retention' => 'interest', 'num_replicas' => 3], 'cluster' => ['name' => 'east', 'leader' => 'n1']])));
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('serverInfo')->willReturn(new ServerInfo('S', 'n', '2.15.2', true, 1_048_576, true));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE + ['max_deliver' => 3]);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);
        $transport->setup();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function streamsNatsModeLosesStoppedMessagesIn(): iterable
    {
        $clustered = ['name' => 'east', 'leader' => 'n1'];

        yield 'interest, three replicas in a cluster' => [['config' => ['retention' => 'interest', 'num_replicas' => 3], 'cluster' => $clustered], '2.15.2', 'a stream with interest retention on a clustered server'];
        yield 'interest, one replica in a cluster' => [['config' => ['retention' => 'interest', 'num_replicas' => 1], 'cluster' => $clustered], '2.15.2', 'a stream with interest retention on a clustered server'];
        yield 'interest, a standalone server before 2.12.9' => [['config' => ['retention' => 'interest'], 'cluster' => ['leader' => 'N1']], '2.12.8', 'a stream with interest retention, at a restart of the server'];
        yield 'interest, a standalone 2.14.0' => [['config' => ['retention' => 'interest']], '2.14.0', 'a stream with interest retention, at a restart of the server'];
        yield 'work-queue, three replicas, before 2.12.5' => [['config' => ['retention' => 'workqueue', 'num_replicas' => 3], 'cluster' => $clustered], '2.12.4', 'a work-queue stream with 3 replicas'];
    }

    /**
     * Nothing is logged on a stream that keeps the message, in symfony mode, or in nats mode without max_deliver,
     * where NATS redelivers without end and nothing reaches max_deliver.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $stream
     */
    #[DataProvider('setupsThatKeepWhatMaxDeliverStops')]
    public function testSetupLogsNothingWhereNatsKeepsWhatMaxDeliverStops(array $options, array $stream, string $version): void
    {
        $logger = new RecordingLogger();
        $this->setupWithTheStream($options, $stream, $version, $logger);

        self::assertSame([], $logger->records);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function setupsThatKeepWhatMaxDeliverStops(): iterable
    {
        $natsMode = self::NATS_MODE + ['max_deliver' => 3];
        $clustered = ['name' => 'east', 'leader' => 'n1'];

        yield 'limits, three replicas' => [$natsMode, ['config' => ['retention' => 'limits', 'num_replicas' => 3], 'cluster' => $clustered], '2.10.29'];
        yield 'work-queue, three replicas, from 2.12.5' => [$natsMode, ['config' => ['retention' => 'workqueue', 'num_replicas' => 3], 'cluster' => $clustered], '2.12.5'];
        yield 'work-queue, one replica' => [$natsMode, ['config' => ['retention' => 'workqueue', 'num_replicas' => 1]], '2.10.29'];
        yield 'interest, a standalone 2.12.9' => [$natsMode, ['config' => ['retention' => 'interest'], 'cluster' => ['leader' => 'N1']], '2.12.9'];
        yield 'interest, a standalone 2.14.1' => [$natsMode, ['config' => ['retention' => 'interest']], '2.14.1'];
        yield 'interest, a standalone v2.12.9, a version with a v' => [$natsMode, ['config' => ['retention' => 'interest']], 'v2.12.9'];
        yield 'work-queue without a replica count, which is one, before 2.12.5' => [$natsMode, ['config' => ['retention' => 'workqueue']], '2.12.4'];
        yield 'symfony mode on an interest stream in a cluster' => [['ping_after_idle' => 0, 'max_deliver' => 3], ['config' => ['retention' => 'interest', 'num_replicas' => 3], 'cluster' => $clustered], '2.15.2'];
        yield 'nats mode without max_deliver' => [self::NATS_MODE, ['config' => ['retention' => 'interest', 'num_replicas' => 3], 'cluster' => $clustered], '2.15.2'];
    }

    /**
     * Runs setup() with $options against a stream the server reports as $stream, on a server of version $version.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $stream
     */
    private function setupWithTheStream(array $options, array $stream, string $version, RecordingLogger $logger): void
    {
        $stream['config'] = (is_array($stream['config'] ?? null) ? $stream['config'] : []) + ['name' => 'test-stream', 'subjects' => ['test-topic']];
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete(StreamInfo::fromArray($stream)));
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));

        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('serverInfo')->willReturn(new ServerInfo('S', 'n', $version, true, 1_048_576, true));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options, null, $logger);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);
        $transport->setup();
    }

    /**
     * setup() refuses scheduled_messages on a stream with interest retention where no consumer has interest in the
     * delayed subjects: NATS stores none of the delayed messages there, so each one, Symfony's delayed retries
     * included, would be lost. The consumer is not created. Up to 5.9.0 setup() passed, and a message that failed
     * once with retry_via: copy ran once and was gone.
     *
     * @param list<ConsumerInfo> $consumers
     */
    #[DataProvider('consumersWithoutInterestInTheDelayedSubjects')]
    public function testSetupRefusesScheduledMessagesOnAnInterestStreamNothingHasInterestIn(array $consumers): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete(self::streamWithRetention('interest')));
        $jetStream->expects(self::once())->method('listConsumers')->with('test-stream')->willReturn(Future::complete($consumers));
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true, 'stream_retention' => 'interest']);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->setup();
            self::fail('setup() should have refused scheduled_messages on this stream');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString(
                'scheduled_messages on stream "test-stream": the stream has interest retention and no consumer has interest in "test-topic.delayed.>"',
                $refusal->getMessage(),
            );
            self::assertStringContainsString('Turn scheduled_messages off', $refusal->getMessage());
            self::assertStringContainsString('add a durable consumer filtered on "test-topic.delayed.>" that nobody pulls from', $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{list<ConsumerInfo>}>
     */
    public static function consumersWithoutInterestInTheDelayedSubjects(): iterable
    {
        yield 'no consumer' => [[]];
        yield 'one on the topic, as the transport\'s own' => [[self::consumerFilteredOn('test-topic')]];
        yield 'one on a single delayed subject' => [[self::consumerFilteredOn('test-topic.delayed.abc')]];
        yield 'one on the delayed token alone' => [[self::consumerFilteredOn('test-topic.delayed')]];
        yield 'one on another topic\'s subjects' => [[self::consumerFilteredOn('other-topic.>')]];
        yield 'one with filter subjects that miss' => [[self::consumerFilteredOn(null, ['test-topic', 'other-topic.delayed.>'])]];
        // ">" takes one token or more, so it takes nothing after the UUID.
        yield 'one on a token after the delayed ones' => [[self::consumerFilteredOn('test-topic.delayed.*.>')]];
    }

    /**
     * A consumer whose filter covers the delayed subjects gives them interest, and setup() goes on to create the
     * transport's consumer: an operator's pinning consumer, one on the stream's subjects, or one without a filter.
     */
    #[DataProvider('consumersWithInterestInTheDelayedSubjects')]
    public function testSetupKeepsScheduledMessagesOnAnInterestStreamWithAConsumerOnTheDelayedSubjects(ConsumerInfo $consumer): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete(self::streamWithRetention('interest')));
        $jetStream->expects(self::once())->method('listConsumers')->willReturn(Future::complete([self::consumerFilteredOn('test-topic'), $consumer]));
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true, 'stream_retention' => 'interest']);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * @return iterable<string, array{ConsumerInfo}>
     */
    public static function consumersWithInterestInTheDelayedSubjects(): iterable
    {
        yield 'on the delayed subjects' => [self::consumerFilteredOn('test-topic.delayed.>')];
        yield 'on one token after delayed' => [self::consumerFilteredOn('test-topic.delayed.*')];
        yield 'on the topic and below' => [self::consumerFilteredOn('test-topic.>')];
        yield 'on every subject' => [self::consumerFilteredOn('>')];
        yield 'with a wildcard for the topic' => [self::consumerFilteredOn('*.delayed.*')];
        yield 'without a filter' => [self::consumerFilteredOn(null)];
        yield 'with filter subjects, one of which matches' => [self::consumerFilteredOn(null, ['test-topic', 'test-topic.delayed.>'])];
        // Entries that name no subject are no filter, so the consumer takes every subject.
        yield 'with filter subjects that name no subject' => [self::consumerFilteredOn(null, ['', 5])];
    }

    /**
     * A stream with limits or work-queue retention keeps every delayed message: the consumers are not asked for, and
     * a later pull does not check the stream again.
     */
    #[DataProvider('retentionsThatKeepDelayedMessages')]
    public function testSetupAsksForNoConsumersOnAStreamThatKeepsDelayedMessages(string $retention): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete(self::streamWithRetention($retention)));
        $jetStream->expects(self::never())->method('listConsumers');
        $jetStream->expects(self::never())->method('getStream');
        $jetStream->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $jetStream->method('fetchBatch')->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true, 'stream_retention' => $retention]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function retentionsThatKeepDelayedMessages(): iterable
    {
        yield 'limits' => ['limits'];
        yield 'work-queue' => ['workqueue'];
    }

    /**
     * A worker checks the stream before its first pull, since it sends Symfony's delayed retries, and refuses to pull
     * from an interest stream nothing has interest in the delayed subjects of, at every pull: the worker stops with
     * the reason instead of losing its retries. Up to 5.9.0 it pulled.
     */
    public function testTheFirstPullRefusesScheduledMessagesOnAnInterestStreamNothingHasInterestIn(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('getStream')->willReturn(Future::complete(self::streamWithRetention('interest')));
        $jetStream->expects(self::exactly(2))->method('listConsumers')->willReturn(Future::complete([self::consumerFilteredOn('test-topic')]));
        $jetStream->expects(self::never())->method('fetchBatch');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        foreach ([1, 2] as $pull) {
            try {
                iterator_to_array($transport->get());
                self::fail('pull ' . $pull . ' should have been refused');
            } catch (TransportException $refusal) {
                self::assertSame(
                    'scheduled_messages on stream "test-stream": the stream has interest retention and no consumer has interest in'
                    . ' "test-topic.delayed.>", so NATS would store none of the delayed messages this transport publishes there,'
                    . ' Symfony\'s delayed retries included, and each would be lost. Turn scheduled_messages off (with retry_via:'
                    . ' nak, retries wait through a NAK instead), use a stream with limits or work-queue retention, or add a'
                    . ' durable consumer filtered on "test-topic.delayed.>" that nobody pulls from, which keeps the delayed'
                    . ' messages until they are due.',
                    $refusal->getMessage(),
                );
            }
        }
    }

    /**
     * Once the stream passed, later pulls do not check it again.
     */
    public function testTheStreamIsCheckedOnceForAWorkerWhosePullsGoOn(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('getStream')->willReturn(Future::complete(self::streamWithRetention('interest')));
        $jetStream->expects(self::once())->method('listConsumers')->willReturn(Future::complete([self::consumerFilteredOn('test-topic.delayed.>')]));
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * A stream the worker may not read is let through, as up to 5.9.0, with one warning, and not checked again.
     */
    public function testAStreamThatCannotBeReadIsLetThroughWithOneWarning(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('getStream')->willReturnCallback(static fn (): Future => Future::error(new JetStreamException('refused', 0)));
        $jetStream->expects(self::never())->method('listConsumers');
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturn(Future::complete([]));
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true], null, $logger);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([
            'scheduled_messages on stream "test-stream": the stream and its consumers could not be read (refused), so the'
            . ' transport could not check that the stream keeps delayed messages. A stream with interest retention keeps'
            . ' them only while a consumer has interest in "test-topic.delayed.>".',
        ], $logger->messagesAt('warning'));
    }

    /**
     * Without a logger, a stream that cannot be read is let through as well, and nothing is logged.
     */
    public function testAStreamThatCannotBeReadIsLetThroughWithoutALogger(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getStream')->willReturnCallback(static fn (): Future => Future::error(new JetStreamException('refused', 0)));
        $jetStream->expects(self::once())->method('fetchBatch')->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['scheduled_messages' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * Without scheduled_messages nothing is checked: a pull reads neither the stream nor its consumers.
     */
    public function testWithoutScheduledMessagesAPullChecksNothing(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('getStream');
        $jetStream->expects(self::never())->method('listConsumers');
        $jetStream->expects(self::once())->method('fetchBatch')->willReturn(Future::complete([]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_retention' => 'interest']);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], iterator_to_array($transport->get()));
    }

    private static function streamWithRetention(string $retention): StreamInfo
    {
        return StreamInfo::fromArray(['config' => [
            'name' => 'test-stream',
            'subjects' => ['test-topic', 'test-topic.delayed.>'],
            'retention' => $retention,
            'allow_msg_schedules' => true,
        ]]);
    }

    /**
     * A consumer of the transport's stream with the filter $filter, or the filter subjects $filters, or neither.
     *
     * @param list<mixed>|null $filters
     */
    private static function consumerFilteredOn(?string $filter, ?array $filters = null): ConsumerInfo
    {
        $config = ['ack_policy' => 'explicit', 'deliver_policy' => 'all'];
        if ($filter !== null) {
            $config['filter_subject'] = $filter;
        }
        if ($filters !== null) {
            $config['filter_subjects'] = $filters;
        }

        return new ConsumerInfo(streamName: 'test-stream', name: 'consumer-' . md5((string) json_encode($config)), push: false, raw: ['config' => $config]);
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
            ->willReturn(Future::complete($streamInfo));
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
            ->willReturn(Future::complete(StreamInfo::fromArray(['config' => ['name' => 'test-stream', 'subjects' => ['test-topic'], 'retention' => 'limits']])));
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


    /**
     * Symfony Messenger 8.1 and later deliver a message that could not be decoded as an envelope whose message is the
     * MessageDecodingFailedException, which the worker handles and fails like any message. In nats mode its settling is
     * a TERM, whether the worker rejects it or, with Symfony's retry strategy on, acknowledges the delivery whose retry
     * the transport left out: a NAK brought it back at once, for ever without max_deliver.
     */
    #[DataProvider('settlingsOfAMessageThatCouldNotBeDecoded')]
    public function testNatsModeTermsAMessageThatCouldNotBeDecodedWhenTheWorkerSettlesIt(bool $retryLeftOut, string $settlement): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::never())->method('ack');
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::once())->method('term')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === '$JS.ACK.test-stream.client.1.1.1.0.0'))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new MessageDecodingFailedException('Deserialized data is not a valid Symfony Messenger Envelope.'), [
            new ReceivedStamp('async'),
            new TransportMessageIdStamp('$JS.ACK.test-stream.client.1.1.1.0.0'),
        ]);

        if ($retryLeftOut) {
            $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(1)));
        }
        $transport->{$settlement}($failed);
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function settlingsOfAMessageThatCouldNotBeDecoded(): iterable
    {
        yield 'rejected, Symfony gave up' => [false, 'reject'];
        yield 'acknowledged after its retry was left out (Messenger 7.4.22, 8.1.10 and later)' => [true, 'ack'];
        yield 'rejected after its retry was left out (earlier releases)' => [true, 'reject'];
    }

    /**
     * In symfony mode such a message is settled as any other failure: Symfony's retry strategy decides, here with
     * retry_via=nak, whose retry is a NAK with Symfony's delay, and its failure transport keeps it in the end.
     */
    public function testSymfonyModeLeavesAMessageThatCouldNotBeDecodedToSymfonysRetryStrategy(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(null)));
        $jetStream->expects(self::never())->method('term');
        $jetStream->expects(self::once())->method('nakWithDelay')->with(self::anything(), 1000)->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->encodingSerializer());
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new MessageDecodingFailedException('x'), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.1.1.1.0.0')]);

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(1)));
        $transport->ack($failed);
    }

    /**
     * In nats mode a failure Symfony marks as unrecoverable is TERMed when the worker rejects it: it can never succeed.
     * Up to 5.9.x it was NAKed, and NATS ran the handler again on every delivery up to max_deliver. An ordinary failure
     * is still NAKed, and so is a delivery without Symfony's error details.
     *
     * @param list<StampInterface> $stamps
     */
    #[DataProvider('failuresNatsModeRejects')]
    public function testNatsModeTermsAnUnrecoverableFailureAndNaksTheRest(array $stamps, string $settledWith): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        foreach (['term', 'nak'] as $method) {
            if ($method === $settledWith) {
                $jetStream->expects(self::once())->method($method)->willReturn(Future::complete());
            } else {
                $jetStream->expects(self::never())->method($method);
            }
        }

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE);
        $transport->setJetStreamContext($jetStream);
        $transport->reject(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.1.1.1.0.0'), ...$stamps]));
    }

    /**
     * @return iterable<string, array{list<StampInterface>, string}>
     */
    public static function failuresNatsModeRejects(): iterable
    {
        yield 'unrecoverable' => [[ErrorDetailsStamp::create(new UnrecoverableMessageHandlingException('never'))], 'term'];
        yield 'unrecoverable, wrapped by the handler failure' => [[ErrorDetailsStamp::create(new HandlerFailedException(new Envelope(new \stdClass()), [new UnrecoverableMessageHandlingException('never')]))], 'term'];
        yield 'an ordinary failure' => [[ErrorDetailsStamp::create(new \RuntimeException('not now'))], 'nak'];
        yield 'recoverable' => [[ErrorDetailsStamp::create(new RecoverableMessageHandlingException('later'))], 'nak'];
        yield 'no error details' => [[], 'nak'];
    }

    /**
     * Through Symfony's Worker with its error-details and retry listeners, in nats mode with max_retries 0: an
     * unrecoverable failure runs once and is TERMed. Up to 5.9.x it ran on every delivery max_deliver allowed.
     */
    public function testNatsModeRunsAnUnrecoverableFailureOnceThroughTheWorker(): void
    {
        $settled = [];
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'p'),
        ]));
        foreach (['term', 'nak', 'ack'] as $method) {
            $jetStream->method($method)->willReturnCallback(static function (NatsMessage $message) use (&$settled, $method): Future {
                $settled[] = $method;

                return Future::complete();
            });
        }

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NATS_MODE + ['max_deliver' => 4], $this->decodingSerializer());
        $transport->setJetStreamContext($jetStream);
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new UnrecoverableMessageHandlingException('the invoice does not exist');
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator(new MultiplierRetryStrategy(0)),
        ));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        self::assertSame(['term'], $settled);
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

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $expectedPlacement
     */
    #[DataProvider('streamPlacementProvider')]
    public function testSetupPassesStreamPlacementOnCreate(array $options, array $expectedPlacement): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->with(self::streamConfigEquals([
                'storage' => 'file',
                'num_replicas' => 1,
                'placement' => $expectedPlacement,
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function streamPlacementProvider(): iterable
    {
        yield 'cluster and tags' => [
            ['stream_placement_cluster' => 'east', 'stream_placement_tags' => ['ssd', 'fast']],
            ['cluster' => 'east', 'tags' => ['ssd', 'fast']],
        ];
        yield 'tags only, comma-separated' => [
            ['stream_placement_tags' => 'ssd,fast'],
            ['tags' => ['ssd', 'fast']],
        ];
        yield 'cluster only' => [
            ['stream_placement_cluster' => 'east'],
            ['cluster' => 'east'],
        ];
    }

    public function testSetupUpdateAppliesConfiguredStreamPlacementToAnExistingStream(): void
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
                    'placement' => ['cluster' => 'west'],
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
                return ($options['placement'] ?? null) === ['cluster' => 'east', 'tags' => ['ssd']];
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
            'stream_placement_cluster' => 'east',
            'stream_placement_tags' => ['ssd'],
        ]);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();
    }

    /**
     * A STREAM.UPDATE payload that omits `placement` clears it on the server (verified against
     * nats-server 2.14), so an operator-pinned placement survives only because the transport echoes
     * the live value back whenever the placement options are unset.
     */
    public function testSetupUpdatePreservesServerStreamPlacementWhenNotConfigured(): void
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
                    'placement' => ['tags' => ['hdd']],
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
                return ($options['placement'] ?? null) === ['tags' => ['hdd']];
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

    /**
     * The update path merges the configured placement fields into the live block instead of replacing
     * it: one option never wipes the field the other half of an operator's placement holds, and tags
     * that name the live set in another order keep the live array as the server stores it, because
     * nats-server reads any difference in the block, even a reordered list, as a request to move the
     * stream. The comparison is case-sensitive on purpose (see the transport's doc-comment), so a tag
     * that differs only in case is a change.
     *
     * @param array<string, mixed>|null $livePlacement     `placement` of the live stream config, null for none
     * @param array<string, mixed>      $options           Transport options
     * @param array<string, mixed>|null $expectedPlacement `placement` the update must carry, null for none
     * @param array<string, mixed>      $expectedFields    Other fields the update must carry, by key
     */
    #[DataProvider('mergedPlacementProvider')]
    public function testSetupUpdateMergesConfiguredPlacementIntoTheLivePlacement(
        ?array $livePlacement,
        array $options,
        ?array $expectedPlacement,
        array $expectedFields = [],
    ): void {
        $liveConfiguration = [
            'name' => 'test-stream',
            'subjects' => ['test-topic'],
            'storage' => 'file',
            'num_replicas' => 1,
        ];
        if ($livePlacement !== null) {
            $liveConfiguration['placement'] = $livePlacement;
        }

        $payload = $this->captureStreamUpdatePayload($liveConfiguration, $options);

        if ($expectedPlacement === null) {
            self::assertArrayNotHasKey('placement', $payload);
        } else {
            self::assertSame($expectedPlacement, $payload['placement'] ?? null);
        }
        foreach ($expectedFields as $field => $value) {
            self::assertSame($value, $payload[$field] ?? null, $field);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, array<string, mixed>, array<string, mixed>|null, 3?: array<string, mixed>}>
     */
    public static function mergedPlacementProvider(): iterable
    {
        $pinned = ['cluster' => 'east', 'tags' => ['hdd']];

        yield 'tags only keep the live cluster' => [
            $pinned, ['stream_placement_tags' => 'ssd'], ['cluster' => 'east', 'tags' => ['ssd']],
        ];
        yield 'cluster only keeps the live tags' => [
            $pinned, ['stream_placement_cluster' => 'west'], ['cluster' => 'west', 'tags' => ['hdd']],
        ];
        yield 'a cluster equal to the live one keeps the live tags' => [
            $pinned, ['stream_placement_cluster' => 'east'], $pinned,
        ];
        yield 'both options replace both fields' => [
            $pinned,
            ['stream_placement_cluster' => 'west', 'stream_placement_tags' => ['ssd']],
            ['cluster' => 'west', 'tags' => ['ssd']],
        ];
        yield 'both options equal to the live block leave it as it is' => [
            ['cluster' => 'west', 'tags' => ['ssd']],
            ['stream_placement_cluster' => 'west', 'stream_placement_tags' => ['ssd']],
            ['cluster' => 'west', 'tags' => ['ssd']],
        ];
        yield 'no option echoes the live block, fields the transport does not know included' => [
            ['cluster' => 'east', 'tags' => ['hdd'], 'preferred' => 'n1'],
            [],
            ['cluster' => 'east', 'tags' => ['hdd'], 'preferred' => 'n1'],
        ];
        yield 'tags only on a stream without placement' => [
            null, ['stream_placement_tags' => 'ssd'], ['tags' => ['ssd']],
        ];
        yield 'cluster only on a stream without placement' => [
            null, ['stream_placement_cluster' => 'west'], ['cluster' => 'west'],
        ];
        yield 'no option on a stream without placement sends none' => [
            null, [], null,
        ];
        yield 'a changed tag set replaces the whole list' => [
            ['tags' => ['ssd', 'eu']], ['stream_placement_tags' => 'ssd,nvme'], ['tags' => ['ssd', 'nvme']],
        ];
        yield 'the same tags in another order keep the live array' => [
            ['tags' => ['ssd', 'eu']], ['stream_placement_tags' => 'eu,ssd'], ['tags' => ['ssd', 'eu']],
        ];
        yield 'a repeated live tag is kept as the server stores it' => [
            ['tags' => ['ssd', 'ssd', 'eu']], ['stream_placement_tags' => 'eu,ssd'], ['tags' => ['ssd', 'ssd', 'eu']],
        ];
        yield 'a changed cluster with the same tags in another order' => [
            ['cluster' => 'east', 'tags' => ['ssd', 'eu']],
            ['stream_placement_cluster' => 'west', 'stream_placement_tags' => 'eu,ssd'],
            ['cluster' => 'west', 'tags' => ['ssd', 'eu']],
        ];
        yield 'a tag that differs only in case is a change' => [
            ['tags' => ['!zone:unused']], ['stream_placement_tags' => '!ZONE:unused'], ['tags' => ['!ZONE:unused']],
        ];
        yield 'live tags that are not strings are replaced' => [
            ['tags' => ['ssd', 7]], ['stream_placement_tags' => 'ssd,7'], ['tags' => ['ssd', '7']],
        ];
        yield 'a live tag that is not a string never matches, not even next to its string form' => [
            ['tags' => ['7', 7]], ['stream_placement_tags' => '7'], ['tags' => ['7']],
        ];
        yield 'the same tags in another order keep the live array when the live side is the sorted one' => [
            ['tags' => ['eu', 'ssd']], ['stream_placement_tags' => 'ssd,eu'], ['tags' => ['eu', 'ssd']],
        ];
        yield 'the same tags in another order with a replica change, and no cluster pin made up' => [
            ['tags' => ['ssd', 'eu']],
            ['stream_placement_tags' => 'eu,ssd', 'stream_replicas' => 3],
            ['tags' => ['ssd', 'eu']],
            ['num_replicas' => 3],
        ];
    }

    /**
     * Runs setup() against a stream that already exists with the given live configuration and returns
     * the STREAM.UPDATE payload the transport sent for it.
     *
     * @param array<string, mixed> $liveConfiguration
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function captureStreamUpdatePayload(array $liveConfiguration, array $options): array
    {
        $payload = null;
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->with('test-stream')
            ->willReturn(Future::complete(new StreamInfo(
                name: 'test-stream',
                subjects: ['test-topic'],
                raw: ['config' => $liveConfiguration],
            )));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->with('test-stream', self::callback(static function (array $options) use (&$payload): bool {
                $payload = $options;

                return true;
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

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options);
        $transport->setJetStreamContext($jetStream);

        $transport->setup();

        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * nats-server rejects one STREAM.UPDATE that changes the placement and the replica count with
     * err_code 10123. The transport cannot stage the two changes itself (see the transport's
     * describeSetupRejection()), so the exception says how: it keeps the setup command's RuntimeException
     * type and the server's wording, and carries the client exception as its direct previous, so the
     * err_code stays readable to whoever catches it.
     */
    public function testSetupExplainsHowToStageAMoveAndScaleRejection(): void
    {
        $rejection = new JetStreamException('can not move and scale a stream in a single update', 400, null, 10123);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'stream_placement_tags' => 'ssd',
            'stream_replicas' => 3,
        ]);
        $transport->setJetStreamContext($this->jetStreamRejectingTheUpdate($rejection));

        try {
            $transport->setup();
            self::fail('setup() was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith(
                "Failed to setup NATS stream 'test-stream': can not move and scale a stream in a single update. NATS refuses to change the placement and the replica count of a stream in one update. Stage the two changes:",
                $exception->getMessage(),
            );
            self::assertStringContainsString('stream_replicas', $exception->getMessage());
            self::assertStringContainsString('"nats stream info"', $exception->getMessage());
            self::assertStringContainsString('With auto_setup every send() and get() repeats this update', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertSame($rejection, $exception->getPrevious());
        }
    }

    /**
     * The staging advice is keyed on the err_code alone, so every other rejection of the update keeps the
     * plain wording with the server's message, a placement no server can satisfy included.
     */
    public function testSetupKeepsThePlainWordingForOtherUpdateRejections(): void
    {
        $rejection = new JetStreamException('no suitable peers for placement, tags not matched [nvme]', 400, null, 10005);

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['stream_placement_tags' => 'nvme']);
        $transport->setJetStreamContext($this->jetStreamRejectingTheUpdate($rejection));

        try {
            $transport->setup();
            self::fail('setup() was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                "Failed to setup NATS stream 'test-stream': no suitable peers for placement, tags not matched [nvme]",
                $exception->getMessage(),
            );
            self::assertSame($rejection, $exception->getPrevious());
        }
    }

    /**
     * With auto_setup the done flag latches only after setup() succeeds, so a rejected move-and-scale
     * update is repeated by every send() until the operator stages the configuration, and nothing is
     * published meanwhile. Once the update goes through, the flag latches and the next send() skips
     * setup() as usual.
     */
    public function testAutoSetupRepeatsARejectedMoveAndScaleUpdateOnEverySend(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);

        $jetStream = $this->jetStreamRejectingTheUpdateTwice();
        $jetStream->expects(self::exactly(2))->method('publish')->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('fetchBatch');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'auto_setup' => true,
            'stream_placement_tags' => 'ssd',
            'stream_replicas' => 3,
        ], $serializer);
        $transport->setJetStreamContext($jetStream);

        foreach ([1, 2] as $attempt) {
            try {
                $transport->send(new Envelope(new \stdClass()));
                self::fail("send() number {$attempt} was expected to fail while provisioning.");
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Stage the two changes', $exception->getMessage());
            }
        }

        // The operator staged the configuration, so the update goes through: this send() provisions and
        // publishes, and the one after it publishes without provisioning again.
        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * The get() side of the previous test: a worker on a rejected configuration fails every pull without
     * fetching, then recovers once the update is accepted.
     */
    public function testAutoSetupRepeatsARejectedMoveAndScaleUpdateOnEveryGet(): void
    {
        $jetStream = $this->jetStreamRejectingTheUpdateTwice();
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturn(Future::complete([]));
        $jetStream->expects(self::never())->method('publish');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, [
            'auto_setup' => true,
            'stream_placement_tags' => 'ssd',
            'stream_replicas' => 3,
        ]);
        $transport->setJetStreamContext($jetStream);

        foreach ([1, 2] as $attempt) {
            try {
                iterator_to_array($transport->get());
                self::fail("get() number {$attempt} was expected to fail while provisioning.");
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Stage the two changes', $exception->getMessage());
            }
        }

        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * A JetStream double for a stream that exists with an R1 placement on `hdd` and whose update is
     * rejected with the given exception; the consumer is never created.
     */
    private function jetStreamRejectingTheUpdate(JetStreamException $rejection): JetStreamContext
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())
            ->method('addStream')
            ->willReturn(Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::once())
            ->method('getStream')
            ->willReturn(Future::complete($this->pinnedR1StreamInfo()));
        $jetStream->expects(self::once())
            ->method('updateStream')
            ->willReturn(Future::error($rejection));
        $jetStream->expects(self::never())->method('addConsumer');

        return $jetStream;
    }

    /**
     * A JetStream double whose update is rejected with err_code 10123 twice and accepted the third time,
     * for three provisioning attempts in all; the consumer is created once, after the accepted update.
     */
    private function jetStreamRejectingTheUpdateTwice(): JetStreamContext&\PHPUnit\Framework\MockObject\MockObject
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(3))
            ->method('addStream')
            ->willReturnCallback(static fn (): Future => Future::error(new JetStreamException('already exists', 0)));
        $jetStream->expects(self::exactly(3))
            ->method('getStream')
            ->willReturnCallback(fn (): Future => Future::complete($this->pinnedR1StreamInfo()));
        $jetStream->expects(self::exactly(3))
            ->method('updateStream')
            ->willReturnOnConsecutiveCalls(
                Future::error(new JetStreamException('can not move and scale a stream in a single update', 400, null, 10123)),
                Future::error(new JetStreamException('can not move and scale a stream in a single update', 400, null, 10123)),
                Future::complete(),
            );
        $jetStream->expects(self::once())
            ->method('addConsumer')
            ->willReturn(Future::complete(new ConsumerInfo(
                streamName: 'test-stream',
                name: 'client',
                push: false,
                raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
            )));

        return $jetStream;
    }

    private function pinnedR1StreamInfo(): StreamInfo
    {
        return new StreamInfo(
            name: 'test-stream',
            subjects: ['test-topic'],
            raw: [
                'config' => [
                    'name' => 'test-stream',
                    'subjects' => ['test-topic'],
                    'storage' => 'file',
                    'num_replicas' => 1,
                    'placement' => ['tags' => ['hdd']],
                ],
            ],
        );
    }

    /**
     * A failure that is not a JetStream API rejection, here a lost connection while provisioning, keeps
     * the plain wrap: the setup command's RuntimeException naming the stream, the cause's message, code 0
     * and the cause as the previous exception. JetStream rejections take their own arm, see
     * testSetupKeepsThePlainWordingForOtherUpdateRejections.
     */
    public function testSetupWrapsAFailureThatIsNotAJetStreamRejectionWithThePlainWording(): void
    {
        $cause = new ConnectionException('connection refused');
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::error($cause));
        $jetStream->expects(self::never())->method('getStream');
        $jetStream->expects(self::never())->method('addConsumer');

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        try {
            $transport->setup();
            self::fail('setup() was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame("Failed to setup NATS stream 'test-stream': connection refused", $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    /**
     * With `reconnect: true` the client owns the recovery of a lost connection, and a client in the middle of it
     * is left alone: the operation neither PINGs nor dials, though the connection sat idle past ping_after_idle,
     * and goes out on the connection the client is making. Without the option a Connecting client would be
     * checked like any other, which stops its recovery with a dial of the transport's own.
     */
    public function testReconnectModeLeavesAClientInTheMiddleOfItsRecoveryAlone(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');
        $client->expects(self::never())->method('disconnect');
        $client->expects(self::never())->method('statistics');

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['reconnect' => true, 'ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $state = ConnectionState::Connecting;
        $transport->now += 31;
        $transport->ack($envelope);
    }

    /**
     * With `reconnect: true` a failed PING is read against what the client made of the connection, by its state and
     * its reconnect count from before the PING: a client that is recovering, or that already recovered (the PONG
     * belonged to the lost connection), keeps the connection; a client that still reports the same connection Open
     * is replaced, as without the option, since nothing in it recovers a connection it does not know to be lost;
     * a client that gave up is Closed and dialled again.
     */
    #[DataProvider('failedPingOutcomes')]
    public function testReconnectModeReadsAFailedPingAgainstTheClientsRecovery(ConnectionState $stateAfterPing, int $reconnectsAfterPing, string $outcome): void
    {
        $kept = $outcome === 'kept';
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::exactly($kept ? 2 : 1))->method('ack')->willReturn(Future::complete());
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::exactly($kept ? 0 : 1))->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $reconnects = 0;
        $client = $this->clientReportingState($state, $kept ? [Future::complete()] : [Future::complete(), Future::complete()]);
        $client->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->method('statistics')->willReturnCallback(static function () use (&$reconnects): ConnectionStats {
            return new ConnectionStats(0, 0, 0, 0, $reconnects);
        });
        $client->expects(self::once())->method('rtt')->willReturnCallback(
            static function () use (&$state, &$reconnects, $stateAfterPing, $reconnectsAfterPing): Future {
                $state = $stateAfterPing;
                $reconnects = $reconnectsAfterPing;

                return Future::error(new ConnectionException('Broken pipe'));
            },
        );
        $client->expects($outcome === 'replaced' ? self::once() : self::never())->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['reconnect' => true, 'ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 31;
        $transport->ack($envelope);
    }

    /**
     * @return iterable<string, array{ConnectionState, int, string}>
     */
    public static function failedPingOutcomes(): iterable
    {
        yield 'the client is recovering: kept, the operation waits for the recovery' => [ConnectionState::Connecting, 0, 'kept'];
        yield 'the client already recovered: kept, the PONG belonged to the lost connection' => [ConnectionState::Open, 1, 'kept'];
        yield 'the same connection, silent: replaced, as without the option' => [ConnectionState::Open, 0, 'replaced'];
        yield 'the recovery gave up: dialled again, nothing to disconnect' => [ConnectionState::Closed, 0, 'redialled'];
    }

    /**
     * A connection the client recovered behind the failed PING counts as checked: the operation after it does not
     * PING again, though the check was armed by a failure.
     */
    public function testReconnectModeTakesARecoveredConnectionAsChecked(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(3))->method('ack')->willReturnOnConsecutiveCalls(
            Future::error(new ConnectionException('Connection lost')),
            Future::complete(),
            Future::complete(),
        );

        $state = ConnectionState::Idle;
        $reconnects = 0;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->method('statistics')->willReturnCallback(static function () use (&$reconnects): ConnectionStats {
            return new ConnectionStats(0, 0, 0, 0, $reconnects);
        });
        $client->expects(self::once())->method('rtt')->willReturnCallback(static function () use (&$reconnects): Future {
            $reconnects = 1;

            return Future::error(new ConnectionException('Broken pipe'));
        });
        $client->expects(self::never())->method('disconnect');

        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['reconnect' => true]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        try {
            $transport->ack($envelope);
            self::fail('The first ack was expected to fail on the connection.');
        } catch (ConnectionException) {
        }

        $transport->ack($envelope);
        $transport->ack($envelope);
    }

    /**
     * Without the option nothing changes: a failed PING replaces the connection, and the client's reconnect count
     * is never read. The count belongs to the client's recovery, which the default mode does not use.
     */
    public function testWithoutReconnectAFailedPingReplacesTheConnectionAndTheReconnectCountIsNeverRead(): void
    {
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects(self::once())->method('ack')->willReturn(Future::complete());
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects(self::once())->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->expects(self::never())->method('statistics');
        $client->expects(self::once())->method('rtt')->willReturnCallback(static function () use (&$state): Future {
            $state = ConnectionState::Connecting;

            return Future::error(new ConnectionException('Broken pipe'));
        });
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new ClockedNatsTransport(self::VALID_DSN, ['ping_after_idle' => 30]);
        $transport->setClient($client);
        $envelope = (new Envelope(new \stdClass()))->with(new TransportMessageIdStamp('reply-token'));
        $transport->ack($envelope);

        $transport->now += 31;
        $transport->ack($envelope);
    }

    /**
     * With `reconnect: true` and auto_setup, provisioning is verified again once the client reconnected on its own:
     * the done flag alone is not trusted, since the client replaced the connection behind the transport's back and
     * the stream may have gone with the server. Verified before the operation, for a send and for a pull; an
     * operation with the count unchanged does not provision again.
     *
     * @param \Closure(NatsTransport): void $operation
     */
    #[DataProvider('operationsAfterAReconnect')]
    public function testReconnectModeVerifiesProvisioningAgainAfterTheClientReconnected(string $method, mixed $result, \Closure $operation): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $jetStream->expects(self::exactly(3))->method($method)->willReturn(Future::complete($result));

        $reconnects = 0;
        $transport = $this->reconnectModeTransport($jetStream, $reconnects, ['auto_setup' => true]);

        $operation($transport);
        $reconnects = 1;
        $operation($transport);
        $operation($transport);
    }

    /**
     * @return iterable<string, array{string, mixed, \Closure(NatsTransport): void}>
     */
    public static function operationsAfterAReconnect(): iterable
    {
        yield 'send' => ['publish', null, static fn (NatsTransport $transport) => $transport->send(new Envelope(new \stdClass()))];
        yield 'get' => ['fetchBatch', [], static fn (NatsTransport $transport) => iterator_to_array($transport->get())];
    }

    /**
     * A send begun while the client recovers passes the provisioning check with the old count, waits for the
     * recovery inside the publish, and lands on a server without the stream: the client's 503 "No JetStream
     * responder". With auto_setup on and the count moved, setup() runs once and the same frame is published once
     * more, with the same subject, payload, headers and Nats-Msg-Id, so a delayed message keeps its schedule and a
     * deduplicated one its id. Nothing answered the first publish, so the second cannot duplicate it.
     *
     * @param array<string, mixed> $options
     * @param list<StampInterface> $stamps
     */
    #[DataProvider('publishesAfterAReconnect')]
    public function testReconnectModeReprovisionsOnceWhenTheFirstPublishAfterTheReconnectFindsNoResponder(array $options, array $stamps, ?string $expectedSubjectPrefix, bool $expectsMessageId): void
    {
        $publishes = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $reconnects = 0;
        $jetStream->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (string $subject, string $payload, array $headers, ?string $msgId) use (&$publishes, &$reconnects): Future {
                $publishes[] = [$subject, $payload, $headers, $msgId];
                if (count($publishes) === 1) {
                    // The recovery completed inside this publish's wait, on a server without the stream.
                    $reconnects = 1;

                    return Future::error(new JetStreamException('No JetStream responder for subject ' . $subject, 503));
                }

                return Future::complete();
            },
        );

        $transport = $this->reconnectModeTransport($jetStream, $reconnects, ['auto_setup' => true] + $options);
        $transport->send(new Envelope(new \stdClass(), $stamps));

        self::assertCount(2, $publishes);
        self::assertSame($publishes[0], $publishes[1], 'the second publish is the first frame again');
        if ($expectedSubjectPrefix !== null) {
            self::assertStringStartsWith($expectedSubjectPrefix, $publishes[0][0]);
        }
        self::assertSame($expectsMessageId, $publishes[0][3] !== null, 'Nats-Msg-Id present as the options and stamps say');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<StampInterface>, ?string, bool}>
     */
    public static function publishesAfterAReconnect(): iterable
    {
        yield 'plain, no deduplication' => [[], [], 'test-topic', false];
        yield 'deduplicate on' => [['deduplicate' => true], [], 'test-topic', true];
        yield 'application-supplied deduplication id' => [[], [new DeduplicationIdStamp('order-7')], 'test-topic', true];
        yield 'delayed message keeps its scheduled subject' => [['scheduled_messages' => true], [new DelayStamp(5000)], 'test-topic.delayed.', false];
    }

    /**
     * The retry is one setup() and one publish: a second 503 propagates, as the client's exception.
     */
    public function testReconnectModeReprovisionsOnlyOnceForAPublishWithoutAResponder(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $reconnects = 0;
        $jetStream->expects(self::exactly(2))->method('publish')->willReturnCallback(static function () use (&$reconnects): Future {
            $reconnects = 1;

            return Future::error(new JetStreamException('No JetStream responder for subject test-topic', 503));
        });

        $transport = $this->reconnectModeTransport($jetStream, $reconnects, ['auto_setup' => true]);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('No JetStream responder for subject test-topic');

        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * The retry needs all three: the no-responder reply, auto_setup, and a reconnect since the last setup(). A 503
     * with the count unchanged, a 503 without auto_setup, and another JetStream reply after a reconnect all
     * propagate at once, with no second setup() and no second publish.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('publishFailuresNotRetried')]
    public function testReconnectModeDoesNotRetryAPublishOutsideTheNoResponderCase(array $options, int $reconnectsDuringPublish, JetStreamException $reply): void
    {
        $autoSetup = $options['auto_setup'] ?? false;
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects($autoSetup ? self::once() : self::never())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects($autoSetup ? self::once() : self::never())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $reconnects = 0;
        $jetStream->expects(self::once())->method('publish')->willReturnCallback(static function () use (&$reconnects, $reconnectsDuringPublish, $reply): Future {
            $reconnects = $reconnectsDuringPublish;

            return Future::error($reply);
        });

        $transport = $this->reconnectModeTransport($jetStream, $reconnects, $options);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage($reply->getMessage());

        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, JetStreamException}>
     */
    public static function publishFailuresNotRetried(): iterable
    {
        yield 'no responder, but the client did not reconnect' => [['auto_setup' => true], 0, new JetStreamException('No JetStream responder for subject test-topic', 503)];
        yield 'no responder after a reconnect, without auto_setup' => [[], 1, new JetStreamException('No JetStream responder for subject test-topic', 503)];
        yield 'another reply after a reconnect' => [['auto_setup' => true], 1, new JetStreamException('maximum messages exceeded', 400, null, 10077)];
    }

    /**
     * Without the option, a 503 from the publish propagates as it always did and the reconnect count is never read:
     * the transport replaces connections itself there, and clears the done flag when it does.
     */
    public function testWithoutReconnectAPublishWithoutAResponderPropagatesAndTheReconnectCountIsNeverRead(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::error(new JetStreamException('No JetStream responder for subject test-topic', 503)));

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('statistics');
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['auto_setup' => true], $serializer);
        $transport->setClient($client);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('No JetStream responder for subject test-topic');

        $transport->send(new Envelope(new \stdClass()));
    }

    /**
     * A transport with `reconnect: true` over a mocked Open client whose reconnect count the test moves, with the
     * given JetStream double and an encoder that returns a fixed body.
     *
     * @param array<string, mixed> $options
     */
    private function reconnectModeTransport(JetStreamContext $jetStream, int &$reconnects, array $options): RealConnectNatsTransport
    {
        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->method('statistics')->willReturnCallback(static function () use (&$reconnects): ConnectionStats {
            return new ConnectionStats(0, 0, 0, 0, $reconnects);
        });
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['reconnect' => true] + $options, $serializer);
        $transport->setClient($client);

        return $transport;
    }

    private function consumerInfo(): ConsumerInfo
    {
        return new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'test-topic']],
        );
    }

    /** The warning of a last delivery's retry copy published without its delay of $delayMs, with retry_via=nak. */
    private static function lastDeliveryCopyWithoutItsDelay(int $delayMs): string
    {
        return sprintf(
            'retry_via=nak on stream "test-stream", subject "test-topic": the retry of the last delivery the consumer'
            . ' allows was published as a copy at once, without its delay of %d ms. NATS redelivers nothing after that'
            . ' delivery, so no NAK can carry this retry. scheduled_messages: true (NATS 2.12+, not on an interest stream)'
            . ' makes the copy wait, and a max_deliver above the retries Symfony makes keeps it from being needed.',
            $delayMs,
        );
    }

    /**
     * The transport's consumer as the server reports it, with max_deliver $maxDeliver; null is a consumer without a
     * limit, which NATS reports as -1.
     */
    private static function consumerLimitedTo(?int $maxDeliver): ConsumerInfo
    {
        return new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => [
                'ack_policy' => 'explicit',
                'deliver_policy' => 'all',
                'filter_subject' => 'test-topic',
                'max_deliver' => $maxDeliver ?? -1,
            ]],
        );
    }

    /**
     * A server that is shutting down ends every pull in flight with "409 Server Shutdown". The consumer is not
     * missing, so the pull is an empty batch, with and without auto_setup: with it, setup() no longer runs against
     * the server that is going away. The server closes the connection right after, so the next operation checks it
     * first, as after any failure on it, and runs on a new one when the PING goes unanswered.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('autoSetupOnAndOff')]
    public function testAPullTheServerEndedForItsShutdownIsEmptyAndTheNextOperationChecksTheConnection(array $options): void
    {
        $autoSetup = $options['auto_setup'] ?? false;
        $oldConnection = $this->createMock(JetStreamContext::class);
        $oldConnection->expects($autoSetup ? self::once() : self::never())->method('addStream')->willReturn(Future::complete());
        $oldConnection->expects($autoSetup ? self::once() : self::never())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $oldConnection->expects(self::once())->method('fetchBatch')->willReturn(
            Future::error(new JetStreamException('JetStream pull request ended with status 409: Server Shutdown', 409)),
        );
        $newConnection = $this->createMock(JetStreamContext::class);
        $newConnection->expects($autoSetup ? self::once() : self::never())->method('addStream')->willReturn(Future::complete());
        $newConnection->expects($autoSetup ? self::once() : self::never())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $newConnection->expects(self::once())->method('fetchBatch')->willReturn(Future::complete([]));

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete(), Future::complete()]);
        $client->expects(self::exactly(2))->method('jetStream')->willReturnOnConsecutiveCalls($oldConnection, $newConnection);
        $client->expects(self::once())->method('rtt')->willReturn(Future::error(new ConnectionException('Connection lost')));
        $client->expects(self::once())->method('disconnect')->willReturn(Future::complete());

        $transport = new RealConnectNatsTransport(self::VALID_DSN, $options);
        $transport->setClient($client);

        self::assertSame([], iterator_to_array($transport->get()), 'the pull the server ended is an empty batch');
        self::assertSame([], iterator_to_array($transport->get()), 'the next pull checked the connection and ran on a new one');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function autoSetupOnAndOff(): iterable
    {
        yield 'auto_setup on' => [['auto_setup' => true]];
        yield 'auto_setup off' => [[]];
    }

    /**
     * A consumer whose leader moved ends the pull with "409 Leadership Change": an empty batch, and the connection
     * is fine, so the next pull owes no check.
     */
    public function testAPullEndedByALeadershipChangeIsEmptyAndTheNextOneDoesNotCheckTheConnection(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error(new JetStreamException('JetStream pull request ended with status 409: Leadership Change', 409)),
            Future::complete([]),
        );

        $state = ConnectionState::Idle;
        $client = $this->clientReportingState($state, [Future::complete()]);
        $client->method('jetStream')->willReturn($jetStream);
        $client->expects(self::never())->method('rtt');
        $client->expects(self::never())->method('disconnect');

        $transport = new RealConnectNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setClient($client);

        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * A shutdown can end the retried pull after re-provisioning as well: it is as empty there as on the first try,
     * rather than a repeated 409 that propagates.
     */
    public function testAShutdownEndingTheRetriedPullAfterReprovisioningIsEmptyToo(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error(new JetStreamException('JetStream pull request ended with status 409: Consumer Deleted', 409)),
            Future::error(new JetStreamException('JetStream pull request ended with status 409: Server Shutdown', 409)),
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, ['auto_setup' => true]);
        $transport->setJetStreamContext($jetStream);

        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * The 409s that report a configuration error keep their meaning. Without auto_setup they propagate; with it the
     * consumer is provisioned once and the repeated 409 propagates, since no amount of polling heals a pull from a
     * push consumer or a batch above the consumer's maximum, and an empty batch would hide the misconfiguration.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('configurationErrorPullStatuses')]
    public function testA409ReportingAConfigurationErrorStaysVisible(array $options, string $description): void
    {
        $autoSetup = $options['auto_setup'] ?? false;
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly($autoSetup ? 2 : 0))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly($autoSetup ? 2 : 0))->method('addConsumer')->willReturn(Future::complete($this->consumerInfo()));
        $jetStream->expects(self::exactly($autoSetup ? 2 : 1))->method('fetchBatch')->willReturnCallback(
            static fn (): Future => Future::error(new JetStreamException('JetStream pull request ended with status 409: ' . $description, 409)),
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage($description);

        iterator_to_array($transport->get());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function configurationErrorPullStatuses(): iterable
    {
        foreach (['Consumer is push based', 'Exceeded MaxRequestBatch of 1'] as $description) {
            yield $description . ', auto_setup off' => [[], $description];
            yield $description . ', auto_setup on' => [['auto_setup' => true], $description];
        }
    }

    /**
     * The two readings belong to a 409: another status whose description happens to name a shutdown is what it was,
     * here a 503 that propagates without auto_setup.
     */
    public function testOnlyA409CarriesTheServerEndedReadings(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('fetchBatch')->willReturn(
            Future::error(new JetStreamException('JetStream pull request ended with status 503: Server Shutdown', 503)),
        );

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, []);
        $transport->setJetStreamContext($jetStream);

        $this->expectException(JetStreamException::class);
        $this->expectExceptionMessage('status 503: Server Shutdown');

        iterator_to_array($transport->get());
    }

    // ---------------------------------------------------------------------------------------------------------
    // retry_via=nak: Symfony's retry strategy decides, and NATS redelivers the original after Symfony's delay.
    // ---------------------------------------------------------------------------------------------------------

    /** Symfony mode with NAK retries; ping_after_idle 0, so that settling a failure sends no PING first. */
    private const NAK_RETRIES = ['retry_via' => 'nak', 'ping_after_idle' => 0];

    /**
     * The retry copy is left out, and the NAK of the delivery carries Symfony's delay, from the copy's DelayStamp,
     * whichever of ack() (Symfony Messenger 7.4.22, 8.1.10 and later) and reject() (earlier releases) settles it.
     * Nothing is logged: Symfony's retry strategy is on by intent here.
     */
    #[DataProvider('settlements')]
    public function testNakRetryNaksWithTheDelaySymfonyComputed(string $settlement): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::never())->method('ack');
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('term');
        $jetStream->expects(self::once())->method('nakWithDelay')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === 'delivery-1'), 7500)
            ->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, null, $logger);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);

        $transport->send($failed->with(new DelayStamp(7500), new RedeliveryStamp(1)));
        $transport->{$settlement}($failed);

        self::assertSame([], $logger->records);
    }

    /**
     * A delay of 0 from Symfony, or a retry copy without a DelayStamp, is a plain NAK, which NATS redelivers at once.
     *
     * @param list<StampInterface> $stamps
     */
    #[DataProvider('retryCopiesWithoutADelay')]
    public function testNakRetryNaksPlainlyForADelayOfZero(array $stamps): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::once())->method('nak')->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);

        $transport->send($failed->with(...$stamps));
        $transport->ack($failed);
    }

    /**
     * @return iterable<string, array{list<StampInterface>}>
     */
    public static function retryCopiesWithoutADelay(): iterable
    {
        yield 'a delay of 0' => [[new DelayStamp(0), new RedeliveryStamp(1)]];
        yield 'a negative delay' => [[new DelayStamp(-5), new RedeliveryStamp(1)]];
        yield 'no DelayStamp' => [[new RedeliveryStamp(1)]];
    }

    /**
     * A delay too long for the nanoseconds a NAK carries is cut to the longest one that fits, about 292 years.
     */
    public function testNakRetryCutsADelayThatWouldOverflow(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('nakWithDelay')
            ->with(self::anything(), 9_223_372_036_854)
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);

        $transport->send($failed->with(new DelayStamp(PHP_INT_MAX), new RedeliveryStamp(1)));
        $transport->ack($failed);
    }

    /**
     * A failed delivery with no retry copy left out is TERMed, as with retry_via=copy: Symfony gave up on it, or
     * the failure is one it never retries. A failure transport has its copy from Symfony.
     */
    public function testNakRetryTermsADeliverySymfonyDoesNotRetry(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::once())->method('term')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === '$JS.ACK.test-stream.client.4.1.4.0.0'))
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 4]);
        $transport->setJetStreamContext($jetStream);

        $transport->reject(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.4.1.4.0.0')]));
    }


    /**
     * A redelivered message carries the deliveries before it as Symfony's retry count, read from either form of
     * the JetStream reply subject, so that the retry strategy sees which retry this is. A first delivery carries
     * none, and neither does any with retry_via=copy or in nats mode.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('deliveryCounts')]
    public function testGetGivesARedeliveredMessageItsEarlierDeliveriesAsRetryCount(array $options, string $replyTo, int $retryCount): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: $replyTo, payload: 'payload'),
        ]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options, $this->decodingSerializer());
        $transport->setJetStreamContext($jetStream);
        $envelopes = iterator_to_array($transport->get());

        self::assertCount(1, $envelopes);
        self::assertSame($retryCount, RedeliveryStamp::getRetryCountFromEnvelope($envelopes[0]));
        self::assertSame($retryCount > 0, $envelopes[0]->last(RedeliveryStamp::class) !== null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int}>
     */
    public static function deliveryCounts(): iterable
    {
        yield 'first delivery' => [self::NAK_RETRIES, '$JS.ACK.test-stream.client.1.7.7.0.0', 0];
        yield 'third delivery' => [self::NAK_RETRIES, '$JS.ACK.test-stream.client.3.7.9.0.0', 2];
        yield 'third delivery, reply subject with domain and account' => [self::NAK_RETRIES, '$JS.ACK.hub.ACC.test-stream.client.3.7.9.1700000000000000000.0.token', 2];
        yield 'a reply subject that is no JetStream delivery' => [self::NAK_RETRIES, 'delivery-1', 0];
        yield 'third delivery, retry_via=copy' => [['retry_via' => 'copy'], '$JS.ACK.test-stream.client.3.7.9.0.0', 0];
        yield 'third delivery, retry_via not set' => [[], '$JS.ACK.test-stream.client.3.7.9.0.0', 0];
        yield 'third delivery, nats mode' => [self::NATS_MODE, '$JS.ACK.test-stream.client.3.7.9.0.0', 0];
    }

    /**
     * The retry count a message was published with, as a retry copy from retry_via=copy is, counts retries it has
     * made already, so the earlier deliveries are added to it: the count goes on growing with every redelivery.
     */
    #[DataProvider('carriedRetryCounts')]
    public function testGetAddsTheEarlierDeliveriesToTheRetryCountTheMessageCarries(int $carried, string $replyTo, int $retryCount): void
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: $replyTo, payload: 'payload'),
        ]));
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturn(new Envelope(new \stdClass(), [new RedeliveryStamp($carried)]));

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $serializer);
        $transport->setJetStreamContext($jetStream);
        $envelopes = iterator_to_array($transport->get());

        self::assertSame($retryCount, RedeliveryStamp::getRetryCountFromEnvelope($envelopes[0]));
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function carriedRetryCounts(): iterable
    {
        yield 'carried 5, two deliveries before' => [5, '$JS.ACK.test-stream.client.3.7.9.0.0', 7];
        yield 'carried 1, two deliveries before' => [1, '$JS.ACK.test-stream.client.3.7.9.0.0', 3];
        yield 'carried 0, two deliveries before' => [0, '$JS.ACK.test-stream.client.3.7.9.0.0', 2];
        yield 'carried 3, first delivery' => [3, '$JS.ACK.test-stream.client.1.7.7.0.0', 3];
    }

    /**
     * Through Symfony's Worker, its retry listener and its failure-transport listener, with
     * MultiplierRetryStrategy(3, 1000, 2) and no jitter, over four deliveries of a message that keeps failing: the
     * multiplier follows the deliveries (NAKs of 1, 2 and 4 s), willRetry() is true three times and then false, the
     * fourth delivery is TERMed, and the failure transport receives one copy. Nothing is published to NATS and
     * nothing is logged.
     */
    public function testNakRetryFollowsSymfonysRetryStrategyThroughTheWorker(): void
    {
        $settled = [];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'p'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.2.1.2.0.0', payload: 'p'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.3.1.3.0.0', payload: 'p'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.4.1.4.0.0', payload: 'p'),
        ]));
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(null)));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::never())->method('ack');
        $jetStream->method('nakWithDelay')->willReturnCallback(static function (NatsMessage $message, int $delayMs) use (&$settled): Future {
            $settled[] = 'nak ' . $delayMs . ' ' . $message->replyTo;

            return Future::complete();
        });
        $jetStream->method('nak')->willReturnCallback(static function (NatsMessage $message) use (&$settled): Future {
            $settled[] = 'nak ' . $message->replyTo;

            return Future::complete();
        });
        $jetStream->method('term')->willReturnCallback(static function (NatsMessage $message) use (&$settled): Future {
            $settled[] = 'term ' . $message->replyTo;

            return Future::complete();
        });
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->decodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        $failureTransport = new InMemoryTransport();

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new \RuntimeException('always fails');
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        // Jitter 0: the strategy's default of 0.1 would spread each delay by up to 10 %, which reaches the NAK too.
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator(new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0)),
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(self::singleServiceLocator($failureTransport)));
        $willRetry = [];
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$willRetry): void {
            $willRetry[] = $event->willRetry();
        }, -1000);
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(4));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        self::assertSame([
            'nak 1000 $JS.ACK.test-stream.client.1.1.1.0.0',
            'nak 2000 $JS.ACK.test-stream.client.2.1.2.0.0',
            'nak 4000 $JS.ACK.test-stream.client.3.1.3.0.0',
            'term $JS.ACK.test-stream.client.4.1.4.0.0',
        ], $settled);
        self::assertSame([true, true, true, false], $willRetry);
        self::assertCount(1, $failureTransport->getSent());
        self::assertNotNull($failureTransport->getSent()[0]->last(SentToFailureTransportStamp::class));
        self::assertSame([], $logger->records);
    }

    /**
     * The delay a RecoverableMessageHandlingException carries is the delay of the NAK, in place of the strategy's.
     */
    public function testNakRetryNaksWithTheDelayOfARecoverableException(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'p'),
        ]));
        $jetStream->expects(self::once())->method('nakWithDelay')->with(self::anything(), 2500)->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->decodingSerializer());
        $transport->setJetStreamContext($jetStream);

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new RecoverableMessageHandlingException('try later', retryDelay: 2500);
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator(new MultiplierRetryStrategy(3, 1000, 2)),
        ));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    /**
     * With two handlers, one failing as unrecoverable and the other not, Symfony retries the message, and the stamp
     * names the first exception. Symfony mode follows Symfony's decision: the delivery Messenger 7.4.21 and earlier
     * reject after the retry is NAKed with its delay, not TERMed as nats mode TERMs an unrecoverable failure.
     */
    public function testNakRetryNaksAFailureSymfonyRetriesWhoseFirstExceptionIsUnrecoverable(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::never())->method('term');
        $jetStream->expects(self::once())->method('nakWithDelay')
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === 'delivery-1'), 1000)
            ->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES);
        $transport->setJetStreamContext($jetStream);
        $received = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('delivery-1')]);
        $failed = $received->with(ErrorDetailsStamp::create(new HandlerFailedException($received, [
            'first' => new UnrecoverableMessageHandlingException('never'),
            'second' => new \RuntimeException('not yet'),
        ])));

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(1)));
        $transport->reject($failed);
    }

    /**
     * An UnrecoverableMessageHandlingException is not retried: its first delivery is TERMed, and the failure transport
     * receives one copy.
     */
    public function testNakRetryTermsAnUnrecoverableFailure(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'p'),
        ]));
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::once())->method('term')->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->decodingSerializer());
        $transport->setJetStreamContext($jetStream);
        $failureTransport = new InMemoryTransport();

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new UnrecoverableMessageHandlingException('never');
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator(new MultiplierRetryStrategy(3, 1000, 2)),
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(self::singleServiceLocator($failureTransport)));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        self::assertCount(1, $failureTransport->getSent());
    }

    /**
     * A NAK cannot carry the retry of the last delivery max_deliver allows: NATS redelivers nothing after it, and
     * with no retry and no failure-transport copy the message would never be handled again. So the retry copy of
     * that delivery is published, as with retry_via=copy, and the delivery is settled as the worker asks:
     * acknowledged by Symfony Messenger 7.4.22, 8.1.10 and later, TERMed after the reject() of earlier releases.
     * Nothing is NAKed and nothing is logged.
     */
    #[DataProvider('lastDeliveries')]
    public function testNakRetryPublishesTheCopyOfTheLastDeliveryMaxDeliverAllows(int $maxDeliver, string $delivery, string $settlement, string $settledWith): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo($maxDeliver)));
        $jetStream->expects(self::once())->method('publish')->with('test-topic', 'encoded')->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::never())->method($settledWith === 'ack' ? 'term' : 'ack');
        $jetStream->expects(self::once())->method($settledWith)
            ->with(self::callback(static fn (NatsMessage $message): bool => $message->replyTo === $delivery))
            ->willReturn(Future::complete());
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturn(['body' => 'encoded']);
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => $maxDeliver], $serializer, $logger);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp($delivery)]);

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(2)));
        $transport->{$settlement}($failed);

        self::assertSame([self::lastDeliveryCopyWithoutItsDelay(1000)], $logger->messagesAt('warning'));
        self::assertCount(1, $logger->records);
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function lastDeliveries(): iterable
    {
        yield 'the last delivery, settled by ack() (Messenger 7.4.22, 8.1.10 and later)' => [2, '$JS.ACK.test-stream.client.2.1.2.0.0', 'ack', 'ack'];
        yield 'the last delivery, settled by reject() (earlier releases)' => [2, '$JS.ACK.test-stream.client.2.1.2.0.0', 'reject', 'term'];
        yield 'a delivery past a max_deliver that was lowered' => [2, '$JS.ACK.test-stream.client.3.1.3.0.0', 'ack', 'ack'];
        yield 'max_deliver 1, where every retry is a copy' => [1, '$JS.ACK.test-stream.client.1.1.1.0.0', 'reject', 'term'];
    }

    /**
     * Only a last delivery the transport knows of gets its retry as a copy. Without max_deliver, before the last
     * delivery, and for a delivery token that is no JetStream reply subject, the retry is the NAK with Symfony's
     * delay. Nats mode is not concerned: it NAKs the last delivery with nak_delay, as before.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('retriesBeforeTheLastDelivery')]
    public function testNakRetryNaksADeliveryThatIsNotKnownToBeTheLast(array $options, string $delivery, int $delayMs): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $maxDeliver = $options['max_deliver'] ?? null;
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(is_int($maxDeliver) ? $maxDeliver : null)));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::once())->method('nakWithDelay')->with(self::anything(), $delayMs)->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, $options);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp($delivery)]);

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(1)));
        $transport->ack($failed);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int}>
     */
    public static function retriesBeforeTheLastDelivery(): iterable
    {
        yield 'retry_via=nak without max_deliver' => [self::NAK_RETRIES, '$JS.ACK.test-stream.client.9.1.9.0.0', 1000];
        yield 'retry_via=nak, a delivery token that is no JetStream reply subject' => [self::NAK_RETRIES + ['max_deliver' => 1], 'delivery-1', 1000];
        yield 'retry_via=nak, the delivery before the last' => [self::NAK_RETRIES + ['max_deliver' => 10], '$JS.ACK.test-stream.client.9.1.9.0.0', 1000];
        yield 'nats mode at max_deliver' => [self::NATS_MODE + ['max_deliver' => 9, 'nak_delay' => 5], '$JS.ACK.test-stream.client.9.1.9.0.0', 5000];
    }

    /**
     * Through Symfony's Worker with max_deliver 2, below the max_retries of MultiplierRetryStrategy(3, 1000, 2):
     * Symfony's strategy still runs to its end, four runs in all.
     * - The first delivery is NAKed with 1 s.
     * - The second is the last one max_deliver allows, so its retry is published as a copy that carries retry count
     *   2, and the delivery is settled as the worker asks: acknowledged by Symfony Messenger 7.4.22, 8.1.10 and
     *   later, TERMed after the reject() of earlier releases.
     * - The copy is a new message: its first delivery, with retry count 2, is NAKed with 4 s.
     * - Its second, with retry count 3, is the one Symfony gives up on: TERMed, with one failure-transport copy.
     */
    public function testNakRetryRunsSymfonysStrategyToItsEndPastMaxDeliver(): void
    {
        $settled = [];
        $published = [];
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturn(Future::complete([
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.1.1.0.0', payload: 'retry count 0'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.2.1.2.0.0', payload: 'retry count 0'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.1.2.3.0.0', payload: 'retry count 2'),
            new NatsMessage(subject: 'test-topic', sid: 1, replyTo: '$JS.ACK.test-stream.client.2.2.4.0.0', payload: 'retry count 2'),
        ]));
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(2)));
        $jetStream->method('publish')->willReturnCallback(static function (string $subject, string $payload) use (&$published): Future {
            $published[] = $subject . ': ' . $payload;

            return Future::complete();
        });
        $jetStream->method('nakWithDelay')->willReturnCallback(static function (NatsMessage $message, int $delayMs) use (&$settled): Future {
            $settled[] = 'nak ' . $delayMs . ' ' . $message->replyTo;

            return Future::complete();
        });
        foreach (['ack', 'nak', 'term'] as $method) {
            $jetStream->method($method)->willReturnCallback(static function (NatsMessage $message) use (&$settled, $method): Future {
                $settled[] = $method . ' ' . $message->replyTo;

                return Future::complete();
            });
        }

        // Encodes a message as its retry count, and decodes that count back, as a serializer does with the stamp.
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('encode')->willReturnCallback(
            static fn (Envelope $envelope): array => ['body' => 'retry count ' . RedeliveryStamp::getRetryCountFromEnvelope($envelope)],
        );
        $serializer->method('decode')->willReturnCallback(static function (array $encoded): Envelope {
            $carried = (int) substr(is_string($encoded['body'] ?? null) ? $encoded['body'] : '', strlen('retry count '));

            return new Envelope(new \stdClass(), $carried > 0 ? [new RedeliveryStamp($carried)] : []);
        });
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 2], $serializer, $logger);
        $transport->setJetStreamContext($jetStream);
        $failureTransport = new InMemoryTransport();

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (): void {
                throw new \RuntimeException('always fails');
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            self::singleServiceLocator($transport),
            self::singleServiceLocator(new MultiplierRetryStrategy(3, 1000, 2, 0, 0.0)),
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(self::singleServiceLocator($failureTransport)));
        $willRetry = [];
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$willRetry): void {
            $willRetry[] = $event->willRetry();
        }, -1000);
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(4));

        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        self::assertSame(['test-topic: retry count 2'], $published);
        self::assertSame([
            'nak 1000 $JS.ACK.test-stream.client.1.1.1.0.0',
            (self::workerAcknowledgesAMessageItSentForRetry() ? 'ack' : 'term') . ' $JS.ACK.test-stream.client.2.1.2.0.0',
            'nak 4000 $JS.ACK.test-stream.client.1.2.3.0.0',
            'term $JS.ACK.test-stream.client.2.2.4.0.0',
        ], $settled);
        self::assertSame([true, true, true, false], $willRetry);
        self::assertCount(1, $failureTransport->getSent());
        self::assertSame([self::lastDeliveryCopyWithoutItsDelay(2000)], $logger->messagesAt('warning'));
        self::assertCount(1, $logger->records);
    }

    /**
     * The retry of the last delivery the consumer allows is published as a copy whatever the max_deliver option says:
     * NATS applies the consumer's limit. Up to 5.9.0 the option decided, and a consumer limited to 3 with the option
     * unset (the default) or at 5 lost the retry of its third delivery: it was NAKed, NATS redelivered nothing, and
     * no failure-transport copy was made (3 handler runs with max_retries 4, measured on nats-server 2.15.2). The
     * difference is logged once.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('optionsAboveTheConsumersLimit')]
    public function testNakRetryPublishesTheCopyOfTheConsumersLastDeliveryWhateverTheOptionSays(array $options, string $optionDescribed): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::once())->method('getConsumer')->with('test-stream', 'client')->willReturn(Future::complete(self::consumerLimitedTo(3)));
        $jetStream->expects(self::once())->method('publish')->with('test-topic', 'encoded')->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('nak');
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->expects(self::once())->method('ack')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES + $options, $this->encodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.3.1.3.0.0')]);

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(3)));
        $transport->ack($failed);

        self::assertSame([
            'retry_via=nak on stream "test-stream": consumer "client" has max_deliver 3 and the max_deliver option is '
            . $optionDescribed . '. The transport follows the consumer, whose limit NATS applies. Run'
            . ' messenger:setup-transports to give the consumer the option\'s value.',
            self::lastDeliveryCopyWithoutItsDelay(1000),
        ], $logger->messagesAt('warning'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function optionsAboveTheConsumersLimit(): iterable
    {
        yield 'the option unset, the default' => [[], 'unset (no limit)'];
        yield 'the option at 5' => [['max_deliver' => 5], '5'];
    }

    /**
     * Without a logger, a consumer whose limit is not the option's is still followed, and nothing is logged.
     */
    public function testAConsumerWhoseLimitIsNotTheOptionsIsFollowedWithoutALogger(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(3)));
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $jetStream->method('ack')->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->encodingSerializer());
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.3.1.3.0.0')]);
        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(3)));
        $transport->ack($failed);
    }

    /**
     * A consumer that reports max_deliver 0 has no limit, as one that reports -1 does: its first delivery's retry is a
     * NAK, not a copy.
     */
    public function testAConsumerThatReportsMaxDeliverZeroHasNoLimit(): void
    {
        $consumer = self::consumerLimitedTo(null);
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getConsumer')->willReturn(Future::complete(new ConsumerInfo(
            streamName: 'test-stream',
            name: 'client',
            push: false,
            raw: ['config' => ['max_deliver' => 0] + (is_array($consumer->raw['config'] ?? null) ? $consumer->raw['config'] : [])],
        )));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::once())->method('nakWithDelay')->willReturn(Future::complete());

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->encodingSerializer());
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.1.1.1.0.0')]);
        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(1)));
        $transport->ack($failed);
    }

    /**
     * A consumer that allows more deliveries than the option says redelivers the message after the option's last
     * delivery, so that retry is a NAK with Symfony's delay rather than a copy. Logged once.
     */
    public function testNakRetryNaksADeliveryTheConsumerStillRedelivers(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(5)));
        $jetStream->expects(self::never())->method('publish');
        $jetStream->expects(self::once())->method('nakWithDelay')->with(self::anything(), 1000)->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 3], $this->encodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.3.1.3.0.0')]);

        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(3)));
        $transport->ack($failed);

        self::assertCount(1, $logger->messagesAt('warning'));
        self::assertStringContainsString('consumer "client" has max_deliver 5 and the max_deliver option is 3', $logger->messagesAt('warning')[0]);
    }

    /**
     * The consumer's limit is read at the first retry that needs it and trusted for a minute: three retries at 0 s,
     * 59.9 s and 60 s read it twice. Each retry here is of a delivery the consumer redelivers.
     */
    public function testTheConsumersMaxDeliverIsReadAgainOnceItIsAMinuteOld(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('getConsumer')->willReturn(Future::complete(self::consumerLimitedTo(10)));
        $jetStream->expects(self::exactly(3))->method('nakWithDelay')->willReturn(Future::complete());

        $transport = new PausingNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 10], $this->encodingSerializer());
        $transport->setJetStreamContext($jetStream);

        foreach ([1000.0, 1059.9, 1060.0] as $delivery => $now) {
            $transport->now = $now;
            $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp(sprintf('$JS.ACK.test-stream.client.%d.1.%d.0.0', $delivery + 1, $delivery + 1))]);
            $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp($delivery + 1)));
            $transport->ack($failed);
        }
    }

    /**
     * A consumer that cannot be read, here because the server refused the request, leaves the max_deliver option to
     * tell the last delivery, as up to 5.9.0, and is logged once. It is not asked again on that connection, a minute
     * later or not; once the connection is closed and opened again it is.
     */
    public function testAConsumerThatCannotBeReadLeavesTheOptionAndIsNotAskedAgainOnThatConnection(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('getConsumer')->willReturnCallback(static fn (): Future => Future::error(new \RuntimeException('refused')));
        $jetStream->method('publish')->willReturn(Future::complete());
        $jetStream->method('ack')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        // A client that stays open, so that the transport keeps the JetStream context the test gives it after close().
        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('disconnect')->willReturn(Future::complete());

        $transport = new PausingNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 2], $this->encodingSerializer(), $logger);
        $transport->setClient($client);
        $transport->setJetStreamContext($jetStream);
        $retryTheLastDelivery = static function () use ($transport): void {
            $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.2.1.2.0.0')]);
            $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(2)));
            $transport->ack($failed);
        };

        $retryTheLastDelivery();
        $transport->now += 120;
        $retryTheLastDelivery();

        self::assertSame([
            'retry_via=nak on stream "test-stream": consumer "client" could not be read for its max_deliver (refused), so the'
            . ' max_deliver option (2) tells which delivery is the last one. Let this user ask for consumer info'
            . ' ($JS.API.CONSUMER.INFO.test-stream.client).',
            self::lastDeliveryCopyWithoutItsDelay(1000),
        ], $logger->messagesAt('warning'));

        $transport->close();
        $transport->setJetStreamContext($jetStream);
        $retryTheLastDelivery();

        self::assertCount(1, array_filter($logger->messagesAt('warning'), static fn (string $message): bool => str_contains($message, 'could not be read for its max_deliver')), 'the unreadable consumer is logged once per transport object, on the new connection too');
    }

    /**
     * A connection that is replaced is asked for the consumer again: the consumer could not be read on the first one,
     * the client then closed, and the transport dialled again. Three retries of the last delivery read the consumer
     * twice: on the first connection, not again on it, and on the new one.
     */
    public function testAReplacedConnectionAsksForTheConsumerAgain(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->expects(self::exactly(2))->method('getConsumer')->willReturnCallback(static fn (): Future => Future::error(new \RuntimeException('refused')));
        $jetStream->method('publish')->willReturn(Future::complete());
        $jetStream->method('ack')->willReturn(Future::complete());

        $state = ConnectionState::Open;
        $client = $this->createStub(NatsClient::class);
        $client->method('state')->willReturnCallback(static function () use (&$state): ConnectionState {
            return $state;
        });
        $client->method('connect')->willReturnCallback(static function () use (&$state): Future {
            $state = ConnectionState::Open;

            return Future::complete();
        });
        $client->method('jetStream')->willReturn($jetStream);

        $transport = new RealConnectNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 2], $this->encodingSerializer());
        $transport->setClient($client);
        $retryTheLastDelivery = static function () use ($transport): void {
            $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.2.1.2.0.0')]);
            $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(2)));
            $transport->ack($failed);
        };

        $retryTheLastDelivery();
        // The server went away: the next operation finds the client closed and dials again.
        $state = ConnectionState::Closed;
        $retryTheLastDelivery();
        $retryTheLastDelivery();
    }

    /**
     * A consumer read once and unreadable later leaves the value read before, not the option: the consumer limited to 3
     * still has its third delivery's retry published as a copy, with the option unset.
     */
    public function testAConsumerUnreadableLaterLeavesTheValueReadBefore(): void
    {
        $answers = [Future::complete(self::consumerLimitedTo(3))];
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('getConsumer')->willReturnCallback(static function () use (&$answers): Future {
            return array_shift($answers) ?? Future::error(new \RuntimeException('refused'));
        });
        $jetStream->method('nakWithDelay')->willReturn(Future::complete());
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $jetStream->method('ack')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new PausingNatsTransport(self::VALID_DSN, self::NAK_RETRIES, $this->encodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        foreach ([1, 3] as $delivery) {
            $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp(sprintf('$JS.ACK.test-stream.client.%d.1.%d.0.0', $delivery, $delivery))]);
            $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp($delivery)));
            $transport->ack($failed);
            $transport->now += 120;
        }

        $warnings = $logger->messagesAt('warning');
        self::assertCount(3, $warnings);
        self::assertStringContainsString('consumer "client" has max_deliver 3 and the max_deliver option is unset (no limit)', $warnings[0]);
        self::assertStringContainsString('could not be read for its max_deliver (refused), so the value read before (3) tells', $warnings[1]);
        self::assertSame(self::lastDeliveryCopyWithoutItsDelay(1000), $warnings[2]);
    }

    /**
     * setup()'s answer is not trusted for the consumer's limit: another configuration's setup may change it right
     * after. Here setup() wrote max_deliver 5, and the consumer reports 3 by the first retry, which is asked for it and
     * publishes the copy of the third delivery. Up to 5.9.0 the option decided, and that retry was NAKed and lost.
     */
    public function testARetryAfterSetupAsksForTheConsumersLimitAgain(): void
    {
        $jetStream = $this->createMock(JetStreamContext::class);
        $jetStream->method('addStream')->willReturn(Future::complete());
        // The first answer is setup()'s lookup of the consumer's replay policy, the second the retry's.
        $jetStream->expects(self::exactly(2))->method('getConsumer')->willReturnOnConsecutiveCalls(
            Future::complete(self::consumerLimitedTo(5)),
            Future::complete(self::consumerLimitedTo(3)),
        );
        $jetStream->method('addConsumer')->willReturn(Future::complete(self::consumerLimitedTo(5)));
        $jetStream->expects(self::once())->method('publish')->willReturn(Future::complete());
        $jetStream->expects(self::never())->method('nakWithDelay');
        $jetStream->method('ack')->willReturn(Future::complete());
        $logger = new RecordingLogger();

        $transport = new RuntimeTestableNatsTransport(self::VALID_DSN, self::NAK_RETRIES + ['max_deliver' => 5], $this->encodingSerializer(), $logger);
        $transport->setJetStreamContext($jetStream);
        $transport->setup();
        $failed = new Envelope(new \stdClass(), [new ReceivedStamp('async'), new TransportMessageIdStamp('$JS.ACK.test-stream.client.3.1.3.0.0')]);
        $transport->send($failed->with(new DelayStamp(1000), new RedeliveryStamp(3)));
        $transport->ack($failed);

        self::assertSame([
            'retry_via=nak on stream "test-stream": consumer "client" has max_deliver 3 and the max_deliver option is 5. The'
            . ' transport follows the consumer, whose limit NATS applies. Run messenger:setup-transports to give the'
            . ' consumer the option\'s value.',
            self::lastDeliveryCopyWithoutItsDelay(1000),
        ], $logger->messagesAt('warning'));
    }

    /**
     * Whether the installed Symfony Messenger acknowledges a message it sent for retry, as 7.4.22, 8.1.10 and later
     * do (symfony/symfony#67038), or rejects it, as earlier releases do.
     */
    private static function workerAcknowledgesAMessageItSentForRetry(): bool
    {
        $version = (string) InstalledVersions::getVersion('symfony/messenger');

        return version_compare($version, '8.1.10', '>=')
            || (version_compare($version, '8.0.0', '<') && version_compare($version, '7.4.22', '>='));
    }
}
