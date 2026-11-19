<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\NatsTransportFactory;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class NatsTransportFactoryTest extends TestCase
{
    private NatsTransportFactory $factory;
    private SerializerInterface $mockSerializer;

    protected function setUp(): void
    {
        $this->factory = new NatsTransportFactory();
        $this->mockSerializer = $this->createStub(SerializerInterface::class);
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

    #[Test]
    public function supports_WithLeadingWhitespaceDsn_ReturnsFalse(): void
    {
        // str_starts_with is prefix-strict: a leading space is not the scheme prefix.
        $this->assertFalse($this->factory->supports(' nats-jetstream://localhost:4222/s/t', []));
    }

    #[Test]
    public function supports_WithUppercaseScheme_ReturnsFalse(): void
    {
        // Scheme matching is case-sensitive, matching how Symfony dispatches DSNs to factories.
        $this->assertFalse($this->factory->supports('NATS-JETSTREAM://localhost:4222/s/t', []));
    }

    /**
     * The factory's logger reaches the NATS client of every transport it creates; a factory without one leaves the
     * client without, as before.
     */
    #[Test]
    public function createTransport_HandsTheFactorysLoggerToTheClient(): void
    {
        $dsn = 'nats-jetstream://admin:password@localhost:4222/test-stream/test-topic';
        $logger = new RecordingLogger();

        $withLogger = (new NatsTransportFactory($logger))->createTransport($dsn, [], $this->mockSerializer);
        $withoutLogger = $this->factory->createTransport($dsn, [], $this->mockSerializer);

        $this->assertSame($logger, self::clientLoggerOf($withLogger));
        $this->assertNull(self::clientLoggerOf($withoutLogger));
    }

    /** The logger of the NATS client inside a transport, read the way the builder test reads the client's options. */
    private static function clientLoggerOf(TransportInterface $transport): ?LoggerInterface
    {
        $configuration = (new \ReflectionClass($transport))->getProperty('configuration')->getValue($transport);
        \assert($configuration instanceof NatsTransportConfiguration);
        $connection = (new \ReflectionClass($configuration->client))->getProperty('connection')->getValue($configuration->client);
        \assert(\is_object($connection));
        $options = (new \ReflectionClass($connection))->getProperty('options')->getValue($connection);
        \assert($options instanceof NatsOptions);

        return $options->logger;
    }

    /**
     * give_up_via 'nak' leaves a message Symfony gives up on in the stream, so a transport that also has a failure
     * transport would have it twice: the factory refuses the two together, whether the option is in the options or
     * in the DSN. FrameworkBundle passes the transport's name in the options, and the bundle the names of the
     * transports with a failure transport.
     *
     * @param array<string, mixed> $options
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('deadInPlaceOptions')]
    public function createTransport_RefusesGiveUpViaNakForATransportWithAFailureTransport(string $dsn, array $options): void
    {
        $factory = new NatsTransportFactory(null, ['other', 'async']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("The transport \"async\" has give_up_via 'nak' and a failure transport: a message Symfony gives up on would stay in the stream and be copied to the failure transport as well. Remove the failure transport from this transport (a global framework.messenger.failure_transport applies to every transport), or use give_up_via 'term'.");

        $factory->createTransport($dsn, $options + ['transport_name' => 'async'], $this->mockSerializer);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function deadInPlaceOptions(): iterable
    {
        $dsn = 'nats-jetstream://localhost:4222/test-stream/test-topic';

        yield 'in the options' => [$dsn, ['retry_via' => 'nak', 'give_up_via' => 'nak', 'max_deliver' => 4]];
        yield 'in the DSN' => [$dsn . '?retry_via=nak&give_up_via=nak&max_deliver=4', []];
    }

    /**
     * Everything else is created: give_up_via 'nak' on a transport without a failure transport, on one that is not
     * in the list, on one without a name, and a transport with a failure transport and give_up_via 'term' or unset.
     *
     * @param list<string>         $transportsWithAFailureTransport
     * @param array<string, mixed> $options
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('combinationsTheFactoryAccepts')]
    public function createTransport_AcceptsGiveUpViaNakWithoutAFailureTransportAndAFailureTransportWithoutIt(array $transportsWithAFailureTransport, array $options): void
    {
        $factory = new NatsTransportFactory(null, $transportsWithAFailureTransport);

        $transport = $factory->createTransport('nats-jetstream://localhost:4222/test-stream/test-topic', $options, $this->mockSerializer);

        $this->assertInstanceOf(NatsTransport::class, $transport);
    }

    /**
     * @return iterable<string, array{list<string>, array<string, mixed>}>
     */
    public static function combinationsTheFactoryAccepts(): iterable
    {
        $deadInPlace = ['retry_via' => 'nak', 'give_up_via' => 'nak', 'max_deliver' => 4];

        yield 'nak, no transport has a failure transport' => [[], $deadInPlace + ['transport_name' => 'async']];
        yield 'nak, another transport has a failure transport' => [['other'], $deadInPlace + ['transport_name' => 'async']];
        yield 'nak, a transport without a name' => [['async'], $deadInPlace];
        yield 'nak, a name that is no string' => [['async'], $deadInPlace + ['transport_name' => 7]];
        yield 'term, with a failure transport' => [['async'], ['retry_via' => 'nak', 'give_up_via' => 'term', 'transport_name' => 'async']];
        yield 'not set, with a failure transport' => [['async'], ['retry_via' => 'nak', 'transport_name' => 'async']];
    }
}
