<?php

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Options\RetryHandler;
use IDCT\NatsMessenger\Options\TransportOption;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NatsTransportConfigurationBuilderTest extends TestCase
{
    private const VALID_DSN = 'nats://admin:password@localhost:4222/test-stream/test-topic';

    public function testBuildWithValidDsnReturnsConfiguration(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, []);

        self::assertInstanceOf(NatsTransportConfiguration::class, $configuration);
        self::assertInstanceOf(NatsClient::class, $configuration->client);
        self::assertSame('test-stream', $configuration->streamName);
        self::assertSame('test-topic', $configuration->topic);
        self::assertSame(RetryHandler::SYMFONY, $configuration->retryHandler());
        self::assertFalse($configuration->isNatsRetryHandlerEnabled());
    }

    public function testBuildUsesRetryHandlerFromQuery(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://localhost:4222/test-stream/test-topic?retry_handler=nats',
            []
        );

        self::assertSame(RetryHandler::NATS, $configuration->retryHandler());
        self::assertTrue($configuration->isNatsRetryHandlerEnabled());
    }

    public function testBuildOptionsOverrideQueryOptions(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://localhost:4222/test-stream/test-topic?retry_handler=nats&batching=10',
            [
                'retry_handler' => RetryHandler::SYMFONY->value,
                'batching' => 3,
            ]
        );

        self::assertSame(RetryHandler::SYMFONY, $configuration->retryHandler());
        self::assertSame(3, $configuration->batching());
        self::assertFalse($configuration->isNatsRetryHandlerEnabled());
    }

    public function testBuildWithInvalidRetryHandlerThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid retry_handler option 'invalid'. Allowed values are 'symfony' or 'nats'.");

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['retry_handler' => 'invalid']);
    }

    public function testBuildWithoutPathThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS Stream name not provided');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222', []);
    }

    public function testBuildWithoutTopicThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both stream name and topic name');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/stream-only/', []);
    }

    public function testBuildWithExtraPathSegmentsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both stream name and topic name');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/stream/topic/extra', []);
    }

    public function testBuildWithEmptyConsumerThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The consumer option must be a non-empty string.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['consumer' => '   ']);
    }

    public function testBuildWithInvalidBatchingThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The batching option must be a positive integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['batching' => 0]);
    }

    public function testBuildWithInvalidConnectionTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The connection_timeout option must be a positive value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['connection_timeout' => 0]);
    }

    public function testBuildWithInvalidStreamReplicaCountThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_replicas option must be a positive integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_replicas' => 0]);
    }

    public function testBuildWithInvalidStreamStorageThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid stream_storage option 'disk'. Allowed values are 'file' or 'memory'.");

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_storage' => 'disk']);
    }

    public function testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, [
            'stream_storage' => 'memory',
            'stream_max_messages_per_subject' => '42',
        ]);

        self::assertSame('memory', $configuration->streamStorage()->value);
        self::assertSame(42, $configuration->streamMaxMessagesPerSubject());
    }

    public function testBuildMethodOptionsOverrideQueryForStreamStorageAndPerSubjectLimit(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://localhost:4222/test-stream/test-topic?stream_storage=file&stream_max_messages_per_subject=10',
            [
                'stream_storage' => 'memory',
                'stream_max_messages_per_subject' => 20,
            ]
        );

        self::assertSame('memory', $configuration->streamStorage()->value);
        self::assertSame(20, $configuration->streamMaxMessagesPerSubject());
    }

    public function testBuildWithTlsSchemeUsesTlsServerProtocol(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats-jetstream+tls://localhost:4222/test-stream/test-topic',
            []
        );

        $options = $this->extractNatsOptions($configuration->client);

        self::assertStringStartsWith('tls://', $options->servers[0]);
    }

    public function testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            self::VALID_DSN,
            [
                'tls_required' => true,
                'tls_handshake_first' => true,
                'tls_ca_file' => '/etc/nats/ca.pem',
                'tls_cert_file' => '/etc/nats/cert.pem',
                'tls_key_file' => '/etc/nats/key.pem',
                'tls_key_passphrase' => 'secret-passphrase',
                'tls_peer_name' => 'nats.example.internal',
                'tls_verify_peer' => false,
                'token' => 'api-token',
                'jwt' => 'jwt-value',
                'nkey' => 'nkey-value',
                'username' => 'override-user',
                'password' => 'override-password',
            ]
        );

        $options = $this->extractNatsOptions($configuration->client);

        self::assertTrue($options->tlsRequired);
        self::assertTrue($options->tlsHandshakeFirst);
        self::assertSame('/etc/nats/ca.pem', $options->tlsCaFile);
        self::assertSame('/etc/nats/cert.pem', $options->tlsCertFile);
        self::assertSame('/etc/nats/key.pem', $options->tlsKeyFile);
        self::assertSame('secret-passphrase', $options->tlsKeyPassphrase);
        self::assertSame('nats.example.internal', $options->tlsPeerName);
        self::assertFalse($options->tlsVerifyPeer);
        self::assertSame('api-token', $options->token);
        self::assertSame('jwt-value', $options->jwt);
        self::assertSame('nkey-value', $options->nkey);
        self::assertSame('override-user', $options->username);
        self::assertSame('override-password', $options->password);
    }

    public function testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://dsn-user:dsn-pass@localhost/test-stream/test-topic',
            []
        );

        $options = $this->extractNatsOptions($configuration->client);

        self::assertSame('nats://localhost:4222', $options->servers[0]);
        self::assertSame('dsn-user', $options->username);
        self::assertSame('dsn-pass', $options->password);
    }

    public function testBuildNormalizesStringBooleanAndNullableStringOptions(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            self::VALID_DSN,
            [
                'tls_required' => 'yes',
                'tls_handshake_first' => '1',
                'tls_verify_peer' => '0',
                'tls_ca_file' => '   ',
                'token' => '',
            ]
        );

        $options = $this->extractNatsOptions($configuration->client);

        self::assertTrue($options->tlsRequired);
        self::assertTrue($options->tlsHandshakeFirst);
        self::assertFalse($options->tlsVerifyPeer);
        self::assertNull($options->tlsCaFile);
        self::assertNull($options->token);
    }

    public function testBuildWithNonNumericBatchingThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The batching option must be numeric.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['batching' => 'abc']);
    }

    public function testBuildNormalizesIntegerBooleanOptions(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            self::VALID_DSN,
            ['tls_verify_peer' => 0]
        );

        $options = $this->extractNatsOptions($configuration->client);

        self::assertFalse($options->tlsVerifyPeer);
    }

    public function testBuildWithNegativeStreamMaxMessagesThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_max_messages option must be a non-negative integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_max_messages' => -1]);
    }

    public function testBuildWithNegativeStreamMaxMessagesPerSubjectThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_max_messages_per_subject option must be a non-negative integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_max_messages_per_subject' => -1]);
    }

    public function testBuildWithNonIntegerStreamMaxMessagesThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_max_messages option must be a non-negative integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_max_messages' => 1.5]);
    }

    public function testBuildWithNonIntegerStreamMaxMessagesPerSubjectThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_max_messages_per_subject option must be a non-negative integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_max_messages_per_subject' => 1.5]);
    }

    public function testBuildWithStreamMaxMessagesFromQueryString(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://localhost:4222/test-stream/test-topic?stream_max_messages=500',
            []
        );

        self::assertSame(500, $configuration->streamMaxMessages());
    }

    public function testBuildWithNegativeStreamMaxBytesThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_max_bytes option must be a non-negative integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_max_bytes' => -1]);
    }

    public function testBuildWithConnectionTimeoutPropagatesMs(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            self::VALID_DSN,
            ['connection_timeout' => 2.5]
        );

        $options = $this->extractNatsOptions($configuration->client);
        self::assertSame(2500, $options->connectTimeoutMs);
    }

    private function extractNatsOptions(NatsClient $client): NatsOptions
    {
        $clientReflection = new \ReflectionClass($client);
        $connectionProperty = $clientReflection->getProperty('connection');
        $connection = $connectionProperty->getValue($client);

        $connectionReflection = new \ReflectionClass($connection);
        $optionsProperty = $connectionReflection->getProperty('options');

        /** @var NatsOptions $options */
        $options = $optionsProperty->getValue($connection);

        return $options;
    }

    public function testBuildWithWildcardInStreamNameThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS stream name');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/str*eam/topic', []);
    }

    public function testBuildWithSpaceInTopicThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS topic name');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/stream/top%20ic', []);
    }

    public function testBuildWithDotInStreamNameThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS stream name');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/str.eam/topic', []);
    }

    public function testBuildWithGreaterThanInTopicThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NATS topic name');

        (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/stream/topic%3E', []);
    }

    public function testDefaultOptionsCoversAllTransportOptionCases(): void
    {
        $reflection = new \ReflectionClass(NatsTransportConfigurationBuilder::class);
        $defaultOptions = $reflection->getConstant('DEFAULT_OPTIONS');

        $enumValues = array_map(static fn (TransportOption $case): string => $case->value, TransportOption::cases());

        self::assertEqualsCanonicalizing($enumValues, array_keys($defaultOptions));
    }

    public function testBuildWithScheduledMessagesEnabledSetsFlag(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            self::VALID_DSN,
            ['scheduled_messages' => true]
        );

        self::assertTrue($configuration->isScheduledMessagesEnabled());
    }

    public function testBuildWithScheduledMessagesDisabledByDefault(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            self::VALID_DSN,
            []
        );

        self::assertFalse($configuration->isScheduledMessagesEnabled());
    }

    public function testBuildWithScheduledMessagesFromDsnQueryString(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://admin:password@localhost:4222/test-stream/test-topic?scheduled_messages=1',
            []
        );

        self::assertTrue($configuration->isScheduledMessagesEnabled());
    }

    public function testBuildWithDottedTopicNameSucceeds(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats://localhost:4222/my-stream/orders.created',
            []
        );

        self::assertSame('orders.created', $configuration->topic);
    }

    public function testBuildWithNegativeBatchingThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The batching option must be a positive integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['batching' => -1]);
    }

    public function testBuildWithNonIntegerBatchingFloatThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The batching option must be a positive integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['batching' => 2.5]);
    }

    public function testBuildWithNegativeConnectionTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The connection_timeout option must be a positive value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['connection_timeout' => -1]);
    }

    public function testBuildWithNonNumericConnectionTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The connection_timeout option must be numeric.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['connection_timeout' => 'abc']);
    }

    public function testBuildWithZeroMaxBatchTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The max_batch_timeout option must be a positive value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['max_batch_timeout' => 0]);
    }

    public function testBuildWithNegativeMaxBatchTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The max_batch_timeout option must be a positive value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['max_batch_timeout' => -5]);
    }

    public function testBuildWithNonNumericMaxBatchTimeoutThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The max_batch_timeout option must be numeric.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['max_batch_timeout' => 'fast']);
    }

    public function testBuildWithNegativeStreamReplicasThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_replicas option must be a positive integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_replicas' => -1]);
    }

    public function testBuildWithNonIntegerStreamReplicasThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_replicas option must be a positive integer value.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_replicas' => 2.5]);
    }

    public function testBuildWithNonNumericStreamMaxAgeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The stream_max_age option must be numeric.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['stream_max_age' => 'old']);
    }

    public function testBuildWithArrayBatchingThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The batching option must be numeric.');

        (new NatsTransportConfigurationBuilder())->build(self::VALID_DSN, ['batching' => [1, 2]]);
    }

    public function testBuildWithMalformedDsnThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given NATS DSN is invalid.');

        (new NatsTransportConfigurationBuilder())->build(':///', []);
    }

    public function testBuildWithDsnMissingHostThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given NATS DSN is invalid.');

        (new NatsTransportConfigurationBuilder())->build('nats:///stream/topic', []);
    }

    /**
     * Verifies that every exact DSN string from README.md parses without error.
     *
     * @dataProvider readmeDsnExamplesProvider
     */
    public function testReadmeDsnExamplesParseSuccessfully(string $dsn, string $expectedStream, string $expectedTopic): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build($dsn, []);

        self::assertInstanceOf(NatsTransportConfiguration::class, $configuration);
        self::assertSame($expectedStream, $configuration->streamName);
        self::assertSame($expectedTopic, $configuration->topic);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function readmeDsnExamplesProvider(): iterable
    {
        yield 'README: default port' => [
            'nats-jetstream://localhost/my-stream/my-topic',
            'my-stream',
            'my-topic',
        ];

        yield 'README: custom port' => [
            'nats-jetstream://localhost:5000/my-stream/my-topic',
            'my-stream',
            'my-topic',
        ];

        yield 'README: with authentication' => [
            'nats-jetstream://user:password@localhost:4222/my-stream/my-topic',
            'my-stream',
            'my-topic',
        ];

        yield 'README: with query parameters' => [
            'nats-jetstream://localhost/my-stream/my-topic?consumer=worker&batching=10',
            'my-stream',
            'my-topic',
        ];

        yield 'README: TLS scheme' => [
            'nats-jetstream+tls://localhost:4222/my-stream/my-topic',
            'my-stream',
            'my-topic',
        ];

        yield 'README: quick-start transport' => [
            'nats-jetstream://localhost:4222/my-stream/my-topic',
            'my-stream',
            'my-topic',
        ];

        yield 'README: multi-subject orders' => [
            'nats-jetstream://localhost/events/orders',
            'events',
            'orders',
        ];

        yield 'README: multi-subject payments' => [
            'nats-jetstream://localhost/events/payments',
            'events',
            'payments',
        ];

        yield 'README: fast transport' => [
            'nats-jetstream://localhost/fast-stream/fast-topic',
            'fast-stream',
            'fast-topic',
        ];

        yield 'README: bulk transport' => [
            'nats-jetstream://localhost/bulk-stream/bulk-topic',
            'bulk-stream',
            'bulk-topic',
        ];

        yield 'README: audit transport' => [
            'nats-jetstream://localhost/audit-stream/audit-topic',
            'audit-stream',
            'audit-topic',
        ];

        yield 'README: scheduled messages DSN' => [
            'nats-jetstream://localhost/my-stream/my-topic?scheduled_messages=true',
            'my-stream',
            'my-topic',
        ];
    }

    /**
     * Verifies that all option values from the README "Configuration Options" YAML example
     * are accepted by the builder and produce the expected configuration.
     */
    public function testReadmeConfigurationOptionsAreAccepted(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats-jetstream://localhost:4222/my-stream/my-topic',
            [
                'consumer' => 'my-consumer',
                'batching' => 5,
                'max_batch_timeout' => 1.0,
                'connection_timeout' => 1.0,
                'stream_max_age' => 86400,
                'stream_max_bytes' => 1073741824,
                'stream_max_messages' => 1000000,
                'stream_max_messages_per_subject' => 1000,
                'stream_storage' => 'file',
                'stream_replicas' => 1,
                'retry_handler' => 'symfony',
                'scheduled_messages' => false,
            ]
        );

        self::assertSame('my-consumer', $configuration->consumer());
        self::assertSame(5, $configuration->batching());
        self::assertSame(1000, $configuration->maxBatchTimeoutMs());
        self::assertSame(86400, $configuration->streamMaxAgeSeconds());
        self::assertSame(1073741824, $configuration->streamMaxBytes());
        self::assertSame(1000000, $configuration->streamMaxMessages());
        self::assertSame(1000, $configuration->streamMaxMessagesPerSubject());
        self::assertSame('file', $configuration->streamStorage()->value);
        self::assertSame(1, $configuration->streamReplicas());
        self::assertSame(RetryHandler::SYMFONY, $configuration->retryHandler());
        self::assertFalse($configuration->isScheduledMessagesEnabled());
    }

    /**
     * Verifies that README batching examples (1, 5, 20, 50) are all accepted.
     */
    public function testReadmeBatchingExamplesAreAccepted(): void
    {
        $builder = new NatsTransportConfigurationBuilder();

        foreach ([1, 5, 10, 20, 50] as $batchSize) {
            $configuration = $builder->build(self::VALID_DSN, ['batching' => $batchSize]);
            self::assertSame($batchSize, $configuration->batching(), "Batching {$batchSize} should be accepted");
        }
    }

    /**
     * Verifies that README timeout examples are accepted.
     */
    public function testReadmeTimeoutExamplesAreAccepted(): void
    {
        $builder = new NatsTransportConfigurationBuilder();

        // max_batch_timeout examples: 0.5, 1.0, 2.0 (seconds → milliseconds)
        $timeoutCases = [[0.5, 500], [1.0, 1000], [2.0, 2000]];
        foreach ($timeoutCases as [$timeout, $expectedMs]) {
            $configuration = $builder->build(self::VALID_DSN, ['max_batch_timeout' => $timeout]);
            self::assertSame($expectedMs, $configuration->maxBatchTimeoutMs(), "max_batch_timeout {$timeout} should produce {$expectedMs}ms");
        }

        // connection_timeout examples: 1.0, 2.0, 3.0
        foreach ([1.0, 2.0, 3.0] as $timeout) {
            $configuration = $builder->build(self::VALID_DSN, ['connection_timeout' => $timeout]);
            self::assertNotNull($configuration, "connection_timeout {$timeout} should be accepted");
        }
    }

    /**
     * Verifies that README stream retention policy examples are accepted.
     */
    public function testReadmeStreamRetentionExamplesAreAccepted(): void
    {
        $builder = new NatsTransportConfigurationBuilder();

        // stream_max_age: 86400 (24h) and 0 (unlimited)
        $config = $builder->build(self::VALID_DSN, ['stream_max_age' => 86400]);
        self::assertSame(86400, $config->streamMaxAgeSeconds());

        $config = $builder->build(self::VALID_DSN, ['stream_max_age' => 0]);
        self::assertSame(0, $config->streamMaxAgeSeconds());

        // stream_max_bytes: 1073741824 (1GB)
        $config = $builder->build(self::VALID_DSN, ['stream_max_bytes' => 1073741824]);
        self::assertSame(1073741824, $config->streamMaxBytes());

        // stream_max_messages: 1000000
        $config = $builder->build(self::VALID_DSN, ['stream_max_messages' => 1000000]);
        self::assertSame(1000000, $config->streamMaxMessages());

        // stream_max_messages_per_subject: 1000
        $config = $builder->build(self::VALID_DSN, ['stream_max_messages_per_subject' => 1000]);
        self::assertSame(1000, $config->streamMaxMessagesPerSubject());

        // stream_replicas: 1 and 3
        $config = $builder->build(self::VALID_DSN, ['stream_replicas' => 1]);
        self::assertSame(1, $config->streamReplicas());

        $config = $builder->build(self::VALID_DSN, ['stream_replicas' => 3]);
        self::assertSame(3, $config->streamReplicas());

        // stream_storage: 'file' and 'memory'
        $config = $builder->build(self::VALID_DSN, ['stream_storage' => 'file']);
        self::assertSame('file', $config->streamStorage()->value);

        $config = $builder->build(self::VALID_DSN, ['stream_storage' => 'memory']);
        self::assertSame('memory', $config->streamStorage()->value);
    }

    /**
     * Verifies README query parameter DSN example produces correct option values.
     */
    public function testReadmeQueryParamDsnProducesCorrectValues(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats-jetstream://localhost/my-stream/my-topic?consumer=worker&batching=10',
            []
        );

        self::assertSame('worker', $configuration->consumer());
        self::assertSame(10, $configuration->batching());
    }

    /**
     * Verifies README scheduled messages DSN example enables the feature.
     */
    public function testReadmeScheduledMessagesDsnEnablesFeature(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(
            'nats-jetstream://localhost/my-stream/my-topic?scheduled_messages=true',
            []
        );

        self::assertTrue($configuration->isScheduledMessagesEnabled());
    }

    /**
     * Verifies README multi-subject delayed DSN example with options.
     */
    public function testReadmeMultiSubjectOptionsAreAccepted(): void
    {
        $builder = new NatsTransportConfigurationBuilder();

        // Orders transport
        $config = $builder->build('nats-jetstream://localhost/events/orders', [
            'consumer' => 'order-consumer',
            'delay' => 0.5,
            'batching' => 1,
            'stream_max_age' => 300,
        ]);
        self::assertSame('events', $config->streamName);
        self::assertSame('orders', $config->topic);
        self::assertSame('order-consumer', $config->consumer());
        self::assertSame(1, $config->batching());
        self::assertSame(300, $config->streamMaxAgeSeconds());

        // Payments transport
        $config = $builder->build('nats-jetstream://localhost/events/payments', [
            'consumer' => 'payment-consumer',
            'delay' => 1,
            'batching' => 2,
        ]);
        self::assertSame('events', $config->streamName);
        self::assertSame('payments', $config->topic);
        self::assertSame('payment-consumer', $config->consumer());
        self::assertSame(2, $config->batching());
    }

    /**
     * Verifies README audit transport example with retention and HA options.
     */
    public function testReadmeAuditTransportOptionsAreAccepted(): void
    {
        $config = (new NatsTransportConfigurationBuilder())->build(
            'nats-jetstream://localhost/audit-stream/audit-topic',
            [
                'consumer' => 'audit-consumer',
                'stream_max_age' => 2592000,
                'stream_replicas' => 3,
            ]
        );

        self::assertSame('audit-stream', $config->streamName);
        self::assertSame('audit-topic', $config->topic);
        self::assertSame('audit-consumer', $config->consumer());
        self::assertSame(2592000, $config->streamMaxAgeSeconds());
        self::assertSame(3, $config->streamReplicas());
    }
}
