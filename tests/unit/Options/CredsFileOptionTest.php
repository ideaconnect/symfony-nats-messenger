<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NATS\Connection\NatsOptions;
use IDCT\NatsMessenger\Options\CredentialsFile;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Tests\Support\PhpProcess;
use IDCT\NatsMessenger\Tests\Support\TestCredsFiles;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The creds_file option when the transport is built: what the client gets from it, and what is refused. The logins
 * themselves are in LoginCombinationsTest, CredsFileConnectTest and the functional suite.
 *
 * The failures run with exception arguments on (zend.exception_ignore_args=0, PHP's default without a php.ini), and no
 * frame of one may hold the seed: the client's parser takes the whole text of the file as its argument.
 */
#[RequiresPhpExtension('sodium')]
final class CredsFileOptionTest extends TestCase
{
    private const DSN = 'nats://localhost:4222/test-stream/test-topic';

    private TestCredsFiles $files;

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->files = new TestCredsFiles();
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
        $this->files->remove();
    }

    /**
     * The client gets a provider that reads the JWT from the file on every connect, and the file as its signer. It gets
     * no static JWT, and no nkey: the server takes the user's key from the JWT.
     */
    public function testTheClientGetsAProviderAndASignerFromTheFileAndNoStaticJwt(): void
    {
        $path = $this->files->write();

        $configuration = (new NatsTransportConfigurationBuilder())->build(self::DSN, ['creds_file' => $path]);
        $options = self::clientOptionsOf($configuration);

        self::assertNull($options->jwt);
        self::assertNull($options->nkey);
        self::assertInstanceOf(CredentialsFile::class, $options->nonceSigner);
        self::assertSame($options->nonceSigner, $configuration->credentialsFile());
        self::assertSame($path, $configuration->credentialsFile()->path);
        self::assertInstanceOf(\Closure::class, $options->jwtProvider);
        self::assertSame(TestCredsFiles::jwt(), ($options->jwtProvider)());
    }

    /**
     * A path is a plain string, so the DSN query takes it too.
     */
    public function testTheDsnQueryTakesThePath(): void
    {
        $path = $this->files->write();

        $configuration = (new NatsTransportConfigurationBuilder())->build(self::DSN . '?creds_file=' . rawurlencode($path));

        self::assertSame($path, $configuration->credentialsFile()?->path);
    }

    /**
     * Only null means no file, as %env(default::NATS_CREDS_FILE)% gives for an unset variable.
     */
    public function testNullMeansNoFile(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(self::DSN, ['creds_file' => null]);

        self::assertNull($configuration->credentialsFile());
        self::assertNull(self::clientOptionsOf($configuration)->jwtProvider);
        self::assertNull(self::clientOptionsOf($configuration)->nonceSigner);
    }

    /**
     * The file is checked once at the build, and nothing of what was read stays in the configuration, which the
     * transport keeps for its lifetime.
     */
    public function testTheConfigurationKeepsNoSeed(): void
    {
        $configuration = (new NatsTransportConfigurationBuilder())->build(self::DSN, ['creds_file' => $this->files->write()]);

        self::assertStringNotContainsString(substr(TestNkeys::seed(), 0, 15), print_r($configuration, true));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoPath(): iterable
    {
        yield 'an empty string' => [''];
        yield 'a blank string' => ['   '];
        yield 'a number' => [42];
        yield 'a boolean' => [true];
        yield 'a list' => [['/etc/user.creds']];
    }

    /**
     * Read as no file, an empty value would connect without credentials, as the server's no_auth_user or anonymously,
     * because a variable happens to be empty.
     */
    #[DataProvider('valuesThatAreNoPath')]
    public function testAValueThatIsNoPathIsRefused(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The creds_file option must be the path of a .creds file, or null for no file. For an optional file from an environment variable use the default processor, as in %env(default::NATS_CREDS_FILE)%.');

        (new NatsTransportConfigurationBuilder())->build(self::DSN, ['creds_file' => $value]);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function otherCredentials(): iterable
    {
        yield 'jwt' => [self::DSN, ['jwt' => 'a.user.jwt']];
        yield 'nkey' => [self::DSN, ['nkey' => 'UAPUBLICKEY']];
        yield 'nkey_seed' => [self::DSN, ['nkey_seed' => 'SEED']];
        yield 'a username option' => [self::DSN, ['username' => 'admin']];
        yield 'a password option' => [self::DSN, ['password' => 'a-password']];
        yield 'a token option' => [self::DSN, ['token' => 'a-token']];
        yield 'a user in the DSN' => ['nats://admin:a-password@localhost:4222/test-stream/test-topic', []];
        yield 'a token in the DSN query' => [self::DSN . '?token=a-token', []];
        yield 'a jwt in the DSN query' => [self::DSN . '?jwt=a.user.jwt', []];
    }

    /**
     * The file holds the whole login. Another credential would contradict it, or go to the server next to its JWT.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('otherCredentials')]
    public function testAnotherCredentialNextToTheFileIsRefused(string $dsn, array $options): void
    {
        if (($options['nkey_seed'] ?? null) === 'SEED') {
            $options['nkey_seed'] = TestNkeys::seed();
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The creds_file option cannot be combined with jwt, nkey, nkey_seed, a username, a password or a token, from the '
            . 'DSN or the options: the file holds the user\'s JWT and seed, and the transport logs in with those alone. Remove '
            . 'the others, or remove creds_file.'
        );

        (new NatsTransportConfigurationBuilder())->build($dsn, $options + ['creds_file' => $this->files->write()]);
    }

    /**
     * Each way a file can fail the check, with the message it gets. Every message names the option and the path, and
     * none repeats the file's text; no frame of the failure, nor of an exception before it, holds the seed.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unusableFiles(): iterable
    {
        yield 'a missing file' => ['missing', 'The creds_file option names no file: %s does not exist or is a directory.'];
        yield 'a directory' => ['directory', 'The creds_file option names no file: %s does not exist or is a directory.'];
        yield 'an unreadable file' => ['unreadable', 'The creds_file option names a file that cannot be read: %s.'];
        yield 'a file without a JWT block' => ['no JWT block', 'The creds_file option names a file that is not a .creds file (Credentials file does not contain a NATS USER JWT block): %s.'];
        yield 'a file without a seed block' => ['no seed block', 'The creds_file option names a file that is not a .creds file (Credentials file does not contain a USER NKEY SEED block): %s.'];
        yield 'a seed block whose END line was cut' => ['seed END line cut', 'The creds_file option names a file that is not a .creds file (Credentials file does not contain a USER NKEY SEED block): %s.'];
        yield 'a JWT block without a JWT' => ['not a JWT', 'The creds_file option names a file whose NATS USER JWT block does not hold a JWT, which has three parts separated by dots: %s.'];
        yield 'a mistyped seed' => ['mistyped seed', 'The creds_file option names a file whose seed cannot be used (Invalid NKey checksum): %s.'];
        yield 'an account seed' => ['account seed', 'The creds_file option names a file whose USER NKEY SEED block does not hold a user seed (SU...): %s.'];
    }

    #[DataProvider('unusableFiles')]
    public function testAnUnusableFileIsRefusedWhenBuiltWithoutItsSeed(string $case, string $message): void
    {
        $path = $this->unusableFile($case);

        try {
            (new NatsTransportConfigurationBuilder())->build(self::DSN, ['creds_file' => $path]);
            self::fail('The file was accepted.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame(sprintf($message, $path), $refused->getMessage());
            self::assertNull($refused->getPrevious(), 'no exception of the client is chained');
            foreach ([TestNkeys::seed(), TestNkeys::mistypedSeed(), TestNkeys::seed('account', TestNkeys::ACCOUNT)] as $seed) {
                self::assertNoSecretIn($refused, $seed);
            }
            self::assertStringNotContainsString(TestCredsFiles::jwt(), $refused->getMessage());
        }
    }

    /**
     * A sodium function the client calls can be disabled while the one it checks for is not: an \Error, whose frames hold
     * the seed, so it is not chained either. Only a child process can disable a function.
     */
    public function testADisabledSodiumFunctionIsRefusedWhenBuiltWithoutChainingTheError(): void
    {
        $path = $this->files->write();
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            try {
                (new IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder())->build('nats://localhost:4222/test-stream/test-topic', ['creds_file' => $argv[1]]);
                echo 'built';
            } catch (InvalidArgumentException $refused) {
                echo $refused->getMessage(), $refused->getPrevious() === null ? '|not chained' : '|chained';
            }
            PHP;

        $process = PhpProcess::run(['-d', 'disable_functions=sodium_memzero', '-d', 'zend.exception_ignore_args=0', '-r', $script, '--', $path], dirname(__DIR__, 3));

        self::assertSame(0, $process['exitCode'], PhpProcess::describe($process));
        self::assertSame(
            'The creds_file option names a file whose seed cannot be used (Call to undefined function IDCT\NATS\Auth\sodium_memzero()): ' . $path . '.|not chained',
            $process['stdout'],
            PhpProcess::describe($process),
        );
    }

    private function unusableFile(string $case): string
    {
        $jwt = TestCredsFiles::jwt();
        $seed = TestNkeys::seed();
        $text = TestCredsFiles::text($jwt, $seed);

        switch ($case) {
            case 'missing':
                return $this->files->path('missing.creds');
            case 'directory':
                return $this->files->directory();
            case 'unreadable':
                $path = $this->files->writeText('unreadable.creds', $text);
                chmod($path, 0000);
                if (is_readable($path)) {
                    self::markTestSkipped('This user reads a file whatever its mode, as root does.');
                }

                return $path;
            case 'no JWT block':
                return $this->files->writeText('user.creds', (string) preg_replace('/-----BEGIN NATS USER JWT.*END NATS USER JWT------\n/s', '', $text));
            case 'no seed block':
                return $this->files->writeText('user.creds', (string) preg_replace('/-----BEGIN USER NKEY SEED.*END USER NKEY SEED------\n/s', '', $text));
            case 'seed END line cut':
                return $this->files->writeText('user.creds', str_replace("------END USER NKEY SEED------\n", '', $text));
            case 'not a JWT':
                return $this->files->writeText('user.creds', TestCredsFiles::text('not-a-jwt', $seed));
            case 'mistyped seed':
                return $this->files->writeText('user.creds', TestCredsFiles::text($jwt, TestNkeys::mistypedSeed()));
            case 'account seed':
                return $this->files->writeText('user.creds', TestCredsFiles::text($jwt, TestNkeys::seed('account', TestNkeys::ACCOUNT)));
        }

        self::fail('Unknown case: ' . $case);
    }

    private static function clientOptionsOf(NatsTransportConfiguration $configuration): NatsOptions
    {
        $connection = (new \ReflectionProperty($configuration->client, 'connection'))->getValue($configuration->client);
        self::assertIsObject($connection);
        $options = (new \ReflectionProperty($connection, 'options'))->getValue($connection);
        self::assertInstanceOf(NatsOptions::class, $options);

        return $options;
    }

    /**
     * As SecretsInExceptionsTest checks the seed: not in the message or the string form of the exception, nor in the
     * frames of this package and the client, with their arguments, in the whole chain.
     */
    private static function assertNoSecretIn(Throwable $failure, string $secret): void
    {
        for ($exception = $failure; $exception !== null; $exception = $exception->getPrevious()) {
            $frames = array_filter(
                $exception->getTrace(),
                static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'IDCT\\') && !str_contains($frame['class'] ?? '', '\\Tests\\'),
            );
            self::assertNotSame([], $frames, 'The test looks at no frame.');
            $printed = $exception->getMessage() . "\n" . $exception . "\n" . print_r($frames, true);
            self::assertStringNotContainsString(substr($secret, 0, 15), $printed);
        }
    }
}
