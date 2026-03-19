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
}
