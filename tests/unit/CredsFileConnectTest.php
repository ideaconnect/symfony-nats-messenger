<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\Future;
use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Exception\ConnectionException;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\Transport\TransportClosedException;
use IDCT\NATS\Transport\TransportInterface;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Tests\Support\ClientInjectedNatsTransport;
use IDCT\NatsMessenger\Tests\Support\FaultInjectingNatsServer;
use IDCT\NatsMessenger\Tests\Support\SubscriptionLimitNatsServer;
use IDCT\NatsMessenger\Tests\Support\TestCredsFiles;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * A transport whose credentials come from a .creds file (creds_file) across more than one connection: each connection
 * reads the file again, and a worker keeps running through the expiry of the JWT it logged in with while the file holds
 * a newer one.
 *
 * The real client runs over the scripted server, which records each CONNECT. A test asserts the JWT that CONNECT carries
 * and that its signature verifies against the seed in the file: "the second connection worked" would pass a transport
 * that read the file once, when it was built, as well.
 */
#[RequiresPhpExtension('sodium')]
final class CredsFileConnectTest extends TestCase
{
    private const DSN = 'nats://localhost:4222/orders/orders.created';

    private const NONCE = 'a-nonce-from-the-server';

    /** A budget no operation here uses up, so that nothing depends on how fast this process runs. */
    private const BUDGET_MS = 5000;

    private const EXPIRED = "-ERR 'User Authentication Expired'\r\n";

    private TestCredsFiles $files;

    protected function setUp(): void
    {
        $this->files = new TestCredsFiles();
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function reconnectModes(): iterable
    {
        yield 'reconnect off: the transport dials again' => [false];
        yield 'reconnect on: the client recovers' => [true];
    }

    /**
     * The file is replaced, by another user's, and the connection drops. The connection that replaces it, the client's
     * recovery or the transport's new dial, logs in with what the file holds now.
     */
    #[DataProvider('reconnectModes')]
    public function testTheConnectionAfterALostOneLogsInWithTheReplacedFile(bool $reconnect): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        $transport = $this->transportOver($faults, $this->files->write('user.creds', 'test-user'), $reconnect);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $this->files->write('user.creds', 'other-user');
            $faults->dropConnection();

            if ($reconnect) {
                $transport->send(new Envelope(self::message('m-2')));
            } else {
                // Without reconnect the send that runs into the lost connection fails, and the next one dials again.
                self::assertInstanceOf(ConnectionException::class, self::failureOf(static fn () => $transport->send(new Envelope(self::message('m-2')))));
                $transport->send(new Envelope(self::message('m-3')));
            }
        } finally {
            $transport->close();
        }

        self::assertSame(2, $server->sessions());
        self::assertLoggedInAs('test-user', $server->connects()[0]);
        self::assertLoggedInAs('other-user', $server->connects()[1]);
    }

    /**
     * The server ends the connection at the expiry of the JWT it logged in with, during a pull, while the file already
     * holds a renewed JWT. The pull is an empty batch, and the next get() runs on a connection that logged in with the
     * renewed JWT and receives the message sent before the expiry.
     */
    #[DataProvider('reconnectModes')]
    public function testAWorkerKeepsPullingThroughTheExpiryOfItsJwtWhenTheFileWasRenewed(bool $reconnect): void
    {
        $server = self::server();
        $transport = $this->transportOver($server, $this->files->write('user.creds', 'test-user', time() + 3600), $reconnect);

        try {
            self::assertSame([], iterator_to_array($transport->get(), false), 'the first pull, on the first connection');
            $transport->send(new Envelope(self::message('m-1')));

            $this->files->write('user.creds', 'renewed-user', time() + 7200);
            $server->errorAndCloseOnNextWriteContaining('$JS.API.CONSUMER.MSG.NEXT', self::EXPIRED);

            self::assertSame([], iterator_to_array($transport->get(), false), 'the pull the expiry ended is an empty batch');
            $received = iterator_to_array($transport->get(), false);
        } finally {
            $transport->close();
        }

        self::assertCount(1, $received, 'the message sent before the expiry');
        self::assertSame(2, $server->sessions());
        self::assertLoggedInAs('test-user', $server->connects()[0]);
        self::assertLoggedInAs('renewed-user', $server->connects()[1]);
    }

    /**
     * A file nobody renewed still holds the JWT that expired: the worker stops with the server's reason, not one pull
     * later with a bare Authorization Violation.
     */
    public function testAWorkerStopsWithTheServersReasonWhenTheFileStillHoldsTheExpiredJwt(): void
    {
        $server = self::server();
        $path = $this->files->write('user.creds', 'test-user', time() + 3600);
        $transport = $this->transportOver($server, $path, false);

        try {
            iterator_to_array($transport->get(), false);
            $this->files->write('user.creds', 'test-user', time() - 1);
            $server->errorAndCloseOnNextWriteContaining('$JS.API.CONSUMER.MSG.NEXT', self::EXPIRED);

            $failure = self::failureOf(static fn () => iterator_to_array($transport->get(), false));
        } finally {
            $transport->close();
        }

        self::assertInstanceOf(ConnectionException::class, $failure);
        self::assertStringContainsString('User Authentication Expired', self::messagesOf($failure));
    }

    /**
     * @return iterable<string, array{\Closure(): \Throwable}>
     */
    public static function expiriesARenewedFileCures(): iterable
    {
        yield 'the frame\'s error' => [static fn (): \Throwable => new ConnectionException("Server sent error frame: 'User Authentication Expired'")];
        yield '"Reconnect is disabled" over the frame\'s error' => [static fn (): \Throwable => new ConnectionException('Reconnect is disabled', 0, new ConnectionException("Server sent error frame: 'User Authentication Expired'"))];
    }

    /**
     * The empty batch, in the two forms the client reports the expiry in, and the next operation checks the connection
     * with a PING before it pulls again.
     *
     * @param \Closure(): \Throwable $failure
     */
    #[DataProvider('expiriesARenewedFileCures')]
    public function testAnExpiryIsAnEmptyBatchWhileTheFileHoldsAJwtThatHasNotExpired(\Closure $failure): void
    {
        [$transport, $client] = $this->transportWithAPullThatFails($failure(), ['creds_file' => $this->files->write(expiresAt: time() + 3600)], true);
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));

        self::assertSame([], iterator_to_array($transport->get(), false));
        self::assertSame([], iterator_to_array($transport->get(), false), 'the next pull, after a PING');
    }

    /**
     * @return iterable<string, array{\Closure(): \Throwable, bool, bool}> The failure, whether the transport has a
     *                                                                       creds_file, and whether its JWT has expired
     */
    public static function failuresThatStayFailures(): iterable
    {
        $expired = static fn (): \Throwable => new ConnectionException("Server sent error frame: 'User Authentication Expired'");

        yield 'the file still holds an expired JWT' => [$expired, true, true];
        yield 'no creds_file: a jwt option cannot be renewed' => [$expired, false, false];
        yield 'the account\'s JWT expired, which no user JWT cures' => [static fn (): \Throwable => new ConnectionException("Server sent error frame: 'Account Authentication Expired'"), true, false];
        yield 'a stale connection' => [static fn (): \Throwable => new ConnectionException("Server sent error frame: 'Stale Connection'"), true, false];
        yield '"Reconnect is disabled" over a broken pipe' => [static fn (): \Throwable => new ConnectionException('Reconnect is disabled', 0, new TransportClosedException('Broken pipe')), true, false];
    }

    /**
     * @param \Closure(): \Throwable $failure
     */
    #[DataProvider('failuresThatStayFailures')]
    public function testOtherConnectionFailuresAndAnExpiryNoFileCuresStillEndTheWorker(\Closure $failure, bool $credsFile, bool $expired): void
    {
        $exception = $failure();
        $options = $credsFile ? ['creds_file' => $this->files->write(expiresAt: $expired ? time() - 1 : time() + 3600)] : [];
        [$transport] = $this->transportWithAPullThatFails($exception, $options, false);

        self::assertSame($exception, self::failureOf(static fn () => iterator_to_array($transport->get(), false)));
    }

    /**
     * The transport over $connection, with a real client that logs in with what the builder made of creds_file: its
     * provider and signer, with budgets no operation here uses up.
     */
    private function transportOver(TransportInterface $connection, string $credsFile, bool $reconnect): ClientInjectedNatsTransport
    {
        $transport = new ClientInjectedNatsTransport(self::DSN, [
            'consumer' => 'worker',
            'creds_file' => $credsFile,
            'reconnect' => $reconnect,
            'max_reconnect_attempts' => 3,
            'max_batch_timeout' => 0.5,
            'request_timeout' => self::BUDGET_MS / 1000,
            'connection_timeout' => self::BUDGET_MS / 1000,
        ], new PhpSerializer());

        $built = self::clientOptionsOf(self::configurationOf($transport));
        self::assertNotNull($built->jwtProvider);
        self::assertNotNull($built->nonceSigner);
        $transport->setClient(new NatsClient(new NatsOptions(
            connectTimeoutMs: self::BUDGET_MS,
            requestTimeoutMs: self::BUDGET_MS,
            reconnectEnabled: $reconnect,
            maxReconnectAttempts: 3,
            reconnectDelayMs: 1,
            reconnectMaxDelayMs: 2,
            reconnectJitterMs: 0,
            pingIntervalSeconds: 0,
            jwtProvider: $built->jwtProvider,
            nonceSigner: $built->nonceSigner,
        ), $connection));

        return $transport;
    }

    /**
     * A transport whose first pull fails with $failure and whose later pulls find nothing, on a client that reports its
     * connection open. A mock when the test sets an expectation on it ($checked), a stub otherwise.
     *
     * @param array<string, mixed> $options
     *
     * @return array{ClientInjectedNatsTransport, NatsClient&\PHPUnit\Framework\MockObject\MockObject}|array{ClientInjectedNatsTransport, NatsClient}
     */
    private function transportWithAPullThatFails(\Throwable $failure, array $options, bool $checked): array
    {
        $jetStream = $this->createStub(JetStreamContext::class);
        $jetStream->method('fetchBatch')->willReturnOnConsecutiveCalls(Future::error($failure), Future::complete([]));

        $client = $checked ? $this->createMock(NatsClient::class) : $this->createStub(NatsClient::class);
        $client->method('state')->willReturn(ConnectionState::Open);
        $client->method('connect')->willReturn(Future::complete());
        $client->method('jetStream')->willReturn($jetStream);

        $transport = new ClientInjectedNatsTransport(self::DSN, ['consumer' => 'worker'] + $options, new PhpSerializer());
        $transport->setClient($client);

        return [$transport, $client];
    }

    private static function server(): SubscriptionLimitNatsServer
    {
        $server = new SubscriptionLimitNatsServer('orders', 'orders.created', 'worker');
        $server->nonce = self::NONCE;

        return $server;
    }

    /**
     * The CONNECT carries the JWT of $user's file, a signature of the nonce that verifies against $user's key, and no
     * other credential.
     *
     * @param array<string, mixed> $connect
     */
    private static function assertLoggedInAs(string $user, array $connect): void
    {
        $jwt = $connect['jwt'] ?? null;
        self::assertIsString($jwt);
        self::assertSame($user, self::claimsOf($jwt)['name'] ?? null, 'the JWT of ' . $user);

        $signature = $connect['sig'] ?? null;
        self::assertIsString($signature);
        self::assertTrue(
            sodium_crypto_sign_verify_detached(sodium_base642bin($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), self::NONCE, TestNkeys::rawPublicKey($user)),
            'the signature verifies against the key of ' . $user,
        );

        foreach (['nkey', 'user', 'pass', 'auth_token'] as $other) {
            self::assertArrayNotHasKey($other, $connect);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function claimsOf(string $jwt): array
    {
        $claims = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);
        self::assertIsArray($claims);

        return $claims;
    }

    private static function configurationOf(NatsTransport $transport): NatsTransportConfiguration
    {
        $configuration = (new \ReflectionProperty(NatsTransport::class, 'configuration'))->getValue($transport);
        self::assertInstanceOf(NatsTransportConfiguration::class, $configuration);

        return $configuration;
    }

    private static function clientOptionsOf(NatsTransportConfiguration $configuration): NatsOptions
    {
        $connection = (new \ReflectionProperty($configuration->client, 'connection'))->getValue($configuration->client);
        self::assertIsObject($connection);
        $options = (new \ReflectionProperty($connection, 'options'))->getValue($connection);
        self::assertInstanceOf(NatsOptions::class, $options);

        return $options;
    }

    private static function messagesOf(\Throwable $failure): string
    {
        $messages = [];
        for ($exception = $failure; $exception !== null; $exception = $exception->getPrevious()) {
            $messages[] = $exception->getMessage();
        }

        return implode("\n", $messages);
    }

    private static function message(string $id): \stdClass
    {
        $message = new \stdClass();
        $message->id = $id;

        return $message;
    }

    private static function failureOf(\Closure $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $failure) {
            return $failure;
        }

        self::fail('expected the operation to fail');
    }
}
