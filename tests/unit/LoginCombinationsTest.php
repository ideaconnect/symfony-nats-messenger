<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Exception\NatsException;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Tests\Support\SubscriptionLimitNatsServer;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Whatever credentials a transport is given, either it refuses them when it is built, or the real client takes them and
 * writes a CONNECT that carries them.
 *
 * jwt and nkey shipped in 4.0.0 unable to log in, and nothing noticed for seven months. The unit tests stopped at the values
 * reaching NatsOptions, the client's own tests pass a signer of their own, and the rule linking the two, that jwt and nkey
 * need a signer, is checked only when the client writes the CONNECT. These tests run on every `composer test`, need no
 * Docker, and cross that boundary with the real client over the scripted server. They cannot show that a server accepts a
 * login: that is what the functional suite and the examples are for.
 */
#[RequiresPhpExtension('sodium')]
final class LoginCombinationsTest extends TestCase
{
    private const NONCE = 'a-nonce-from-the-server';
    private const JWT = 'a.user.jwt';
    private const TOKEN = 'a-token';
    private const USERNAME = 'admin';
    private const PASSWORD = 'a-password';

    /**
     * Every combination of the five ways to give credentials, the 32 of them, with what should become of it.
     *
     * @return iterable<string, array{bool, bool, bool, bool, bool}>
     */
    public static function everyCombination(): iterable
    {
        foreach ([false, true] as $userInTheDsn) {
            foreach ([false, true] as $token) {
                foreach ([false, true] as $jwt) {
                    foreach ([false, true] as $nkey) {
                        foreach ([false, true] as $seed) {
                            $names = array_keys(array_filter(['user in the DSN' => $userInTheDsn, 'token' => $token, 'jwt' => $jwt, 'nkey' => $nkey, 'seed' => $seed]));

                            yield $names === [] ? 'no credentials' : implode(', ', $names) => [$userInTheDsn, $token, $jwt, $nkey, $seed];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('everyCombination')]
    public function testCredentialsAreRefusedWhenBuiltOrReachTheServerInTheConnect(bool $userInTheDsn, bool $token, bool $jwt, bool $nkey, bool $seed): void
    {
        $dsn = sprintf('nats://%slocalhost:4222/test-stream/test-topic', $userInTheDsn ? self::USERNAME . ':' . self::PASSWORD . '@' : '');
        $options = array_filter([
            'token' => $token ? self::TOKEN : null,
            'jwt' => $jwt ? self::JWT : null,
            'nkey' => $nkey ? TestNkeys::publicKey() : null,
            'nkey_seed' => $seed ? TestNkeys::seed() : null,
        ]);

        $needsASeed = $jwt || $nkey;
        $excludesASeed = $userInTheDsn || $token;
        if ($seed ? $excludesASeed : $needsASeed) {
            $this->expectException(InvalidArgumentException::class);
            (new NatsTransportConfigurationBuilder())->build($dsn, $options);

            return;
        }

        $connect = $this->connectThatTheClientWrites($dsn, $options);

        self::assertSame($userInTheDsn ? self::USERNAME : null, $connect['user'] ?? null);
        self::assertSame($userInTheDsn ? self::PASSWORD : null, $connect['pass'] ?? null);
        self::assertSame($token ? self::TOKEN : null, $connect['auth_token'] ?? null);
        self::assertSame($jwt ? self::JWT : null, $connect['jwt'] ?? null);
        self::assertSame($seed ? TestNkeys::publicKey() : null, $connect['nkey'] ?? null);

        if (!$seed) {
            self::assertArrayNotHasKey('sig', $connect);

            return;
        }

        self::assertIsString($connect['sig'] ?? null);
        self::assertTrue(
            sodium_crypto_sign_verify_detached(
                sodium_base642bin($connect['sig'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
                self::NONCE,
                TestNkeys::rawPublicKey(),
            ),
            'The signature in the CONNECT does not verify against the public key of the seed.',
        );
    }

    /**
     * The same user and password as options, which nothing else takes through the client: the scenarios put them in the
     * DSN.
     */
    public function testAUsernameAndPasswordAsOptionsReachTheServerInTheConnect(): void
    {
        $connect = $this->connectThatTheClientWrites(
            'nats://localhost:4222/test-stream/test-topic',
            ['username' => self::USERNAME, 'password' => self::PASSWORD],
        );

        self::assertSame(self::USERNAME, $connect['user'] ?? null);
        self::assertSame(self::PASSWORD, $connect['pass'] ?? null);
    }

    /**
     * A server that sends no nonce, such as a password, token or open one, leaves nothing to sign. The README tells a reader
     * to expect this message, and it depends on the server, so it cannot be refused when the transport is built.
     */
    public function testASeedOnAServerThatSendsNoNonceCannotLogIn(): void
    {
        $server = new SubscriptionLimitNatsServer('test-stream', 'test-topic', 'client');

        $this->expectException(NatsException::class);
        $this->expectExceptionMessage('NKey authentication requires server nonce from INFO');

        $this->clientFor('nats://localhost:4222/test-stream/test-topic', ['nkey_seed' => TestNkeys::seed()], $server)->connect()->await();
    }

    /**
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function connectThatTheClientWrites(string $dsn, array $options): array
    {
        $server = new SubscriptionLimitNatsServer('test-stream', 'test-topic', 'client');
        $server->nonce = self::NONCE;

        $client = $this->clientFor($dsn, $options, $server);
        $client->connect()->await();
        $client->disconnect()->await();

        self::assertCount(1, $server->connects());

        return $server->connects()[0];
    }

    /**
     * The real client, with the options the builder made, on the scripted server.
     *
     * @param array<string, string> $options
     */
    private function clientFor(string $dsn, array $options, SubscriptionLimitNatsServer $server): NatsClient
    {
        $built = (new NatsTransportConfigurationBuilder())->build($dsn, $options)->client;
        $connection = (new \ReflectionProperty($built, 'connection'))->getValue($built);
        self::assertIsObject($connection);
        $natsOptions = (new \ReflectionProperty($connection, 'options'))->getValue($connection);
        self::assertInstanceOf(NatsOptions::class, $natsOptions);

        return new NatsClient($natsOptions, $server);
    }
}
