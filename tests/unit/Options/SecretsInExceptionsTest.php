<?php

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NATS\Auth\NkeySeedSigner;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\NatsTransportFactory;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Throwable;

/**
 * What a failed build leaves in its exception, with exception arguments on: zend.exception_ignore_args=0 is PHP's
 * default without a php.ini and what the official php:8.x-cli images run, and tools that record stack frames read them.
 *
 * Each test keeps the secret out of its own frames: PHP records the arguments of every frame down to the test runner, so
 * the tests take the name of a case and look its values up, and no argument is a closure that captures them.
 */
final class SecretsInExceptionsTest extends TestCase
{
    private const PASSWORD = 'a-password-long-enough';

    private const DSN_WITHOUT_CREDENTIALS = 'nats://localhost:4222/test-stream/test-topic';

    /** Stands for the seed in data provider values, which the frames of a test method hold. */
    private const PLACEHOLDER = 'SEED';

    /** Set while a test runs with exception arguments on; restored after it. */
    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failuresOfABuild(): iterable
    {
        foreach (array_keys(self::dsnAndOptionsOfTheFailures()) as $failure) {
            foreach (self::secretForms() as $form) {
                yield $failure . ', ' . $form => [$failure, $form];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formsOfASecret(): iterable
    {
        foreach (self::secretForms() as $form) {
            yield $form => [$form];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formsOfASeed(): iterable
    {
        yield 'seed option' => ['seed option'];
        yield 'seed in the DSN query' => ['seed in the DSN query'];
    }

    /**
     * One case for each place in the build that throws, as the option maps and DSN parts are its arguments.
     */
    #[DataProvider('failuresOfABuild')]
    public function testNoFrameOfAFailedBuildHoldsASecret(string $failure, string $form): void
    {
        [$dsn, $options] = self::dsnAndOptionsOfTheFailures()[$failure];
        [$dsn, $options] = self::withSecret($form, $dsn, $options);

        try {
            (new NatsTransportConfigurationBuilder())->build($dsn, $options);
            self::fail('The build was expected to fail.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertNoSecretIn($thrown, self::secretOf($form));
        }
    }

    /**
     * The frames above the build: Symfony's own factory calls createTransport(), which calls the constructor.
     */
    #[DataProvider('formsOfASecret')]
    public function testNoFrameOfAFailedTransportCreationHoldsASecret(string $form): void
    {
        [$dsn, $options] = self::withSecret($form, self::DSN_WITHOUT_CREDENTIALS, ['retry_handler' => 'nope']);

        try {
            (new NatsTransportFactory())->createTransport($dsn, $options, new PhpSerializer());
            self::fail('The transport was expected to fail.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertNoSecretIn($thrown, self::secretOf($form));
            self::assertStringContainsString('createTransport', $thrown->getTraceAsString());
        }
    }

    /**
     * A parameter under one of these names holds the DSN or its query, the merged options, a credential or its signer, or one
     * value or name that may contain them, whatever the method does with it. A new method that takes one and can throw must not bring the secrets
     * back into the frames.
     */
    public function testEveryParameterThatCanHoldASecretIsMarkedSensitive(): void
    {
        $methods = [
            ...(new \ReflectionClass(NatsTransportConfigurationBuilder::class))->getMethods(),
            ...(new \ReflectionClass(NatsTransportFactory::class))->getMethods(),
            new \ReflectionMethod(NatsTransport::class, '__construct'),
        ];

        $unmarked = [];
        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                if (
                    in_array($parameter->getName(), ['dsn', 'query', 'options', 'configuration', 'components', 'signer', 'name', 'value', 'seed', 'jwt', 'username', 'password', 'token'], true)
                    && $parameter->getAttributes(\SensitiveParameter::class) === []
                ) {
                    $unmarked[] = $method->getDeclaringClass()->getShortName() . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }

        self::assertSame([], $unmarked);
    }

    /**
     * A seed with one character changed fails the client's checksum. Its message is safe to pass on, but the client's
     * exception is not: its frames hold the whole seed as a string argument, and one wrong character is easily repaired.
     */
    #[RequiresPhpExtension('sodium')]
    #[DataProvider('formsOfASeed')]
    public function testAMistypedSeedStaysOutOfTheFailure(string $form): void
    {
        $mistyped = TestNkeys::mistypedSeed();
        [$dsn, $options] = self::withSecret($form, self::DSN_WITHOUT_CREDENTIALS, [], $mistyped);

        try {
            (new NatsTransportConfigurationBuilder())->build($dsn, $options);
            self::fail('A mistyped seed was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertSame(
                'The NKey seed cannot be used: Invalid NKey checksum. A user seed starts with SU, and a .creds file holds it under '
                . 'its BEGIN USER NKEY SEED line.',
                $thrown->getMessage(),
            );
            self::assertNoSecretIn($thrown, $mistyped);
        }
    }

    /**
     * With the seed in the DSN, resolveNkey() was the only frame that could hold it. The signer in that frame's arguments
     * would also print the raw secret key, whose first 32 bytes are the seed itself.
     */
    #[RequiresPhpExtension('sodium')]
    #[DataProvider('formsOfASeed')]
    public function testAContradictingNkeyLeavesTheSeedAndTheSignerOutOfTheFailure(string $form): void
    {
        [$dsn, $options] = self::withSecret($form, self::DSN_WITHOUT_CREDENTIALS, ['nkey' => 'UOTHERPUBLICKEY']);

        try {
            (new NatsTransportConfigurationBuilder())->build($dsn, $options);
            self::fail('An nkey that contradicts the seed was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringStartsWith('The nkey option is not the public key', $thrown->getMessage());
            self::assertNoSecretIn($thrown, TestNkeys::seed());
            self::assertStringNotContainsString('secretKey', self::printedFrames($thrown));
        }
    }

    /**
     * The transport keeps its configuration for its lifetime, and nothing reads the seed after the build, so a dump of
     * either must not print it. The signer in the client still holds the key itself, as raw bytes, until the client hides it.
     */
    #[RequiresPhpExtension('sodium')]
    #[DataProvider('formsOfASeed')]
    public function testTheConfigurationDoesNotKeepTheTextOfTheSeed(string $form): void
    {
        [$dsn, $options] = self::withSecret($form, self::DSN_WITHOUT_CREDENTIALS, []);

        $configuration = (new NatsTransportConfigurationBuilder())->build($dsn, $options);

        self::assertInstanceOf(NkeySeedSigner::class, self::clientOptionsOf($configuration)->nonceSigner);
        self::assertStringNotContainsString(TestNkeys::seed(), print_r($configuration, true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function placesInTheDsnPathForAMistypedQuery(): iterable
    {
        yield 'the topic' => ['nats://localhost:4222/test-stream/test-topic&nkey_seed=' . self::PLACEHOLDER];
        yield 'the stream' => ['nats://localhost:4222/test-stream&nkey_seed=' . self::PLACEHOLDER . '/test-topic'];
    }

    /**
     * A query typed with & instead of ? stays in the path, so the seed is part of the stream or topic name. The error must
     * not print that name, whatever exception arguments are set to.
     */
    #[DataProvider('placesInTheDsnPathForAMistypedQuery')]
    public function testASeedTypedIntoTheDsnPathStaysOutOfTheError(string $dsnWithAPlaceholder): void
    {
        $dsn = str_replace(self::PLACEHOLDER, TestNkeys::seed(), $dsnWithAPlaceholder);

        try {
            (new NatsTransportConfigurationBuilder())->build($dsn);
            self::fail('A stream or topic name with invalid characters was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringContainsString('contains invalid characters', $thrown->getMessage());
            self::assertNoSecretIn($thrown, TestNkeys::seed());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function optionsAMistypedQueryCanEndUpIn(): iterable
    {
        $dsn = 'nats://localhost:4222/test-stream/test-topic';

        yield 'a second ? after a list of allowed values' => [$dsn . '?retry_handler=nats?seed=' . self::PLACEHOLDER];
        yield 'a ; after a list of allowed values' => [$dsn . '?replay_policy=instant;seed=' . self::PLACEHOLDER];
        yield 'a ; after stream_storage' => [$dsn . '?stream_storage=file;seed=' . self::PLACEHOLDER];
        yield 'a ; after a boolean' => [$dsn . '?stream_deny_delete=true;seed=' . self::PLACEHOLDER];
        yield 'a second ? after a number' => [$dsn . '?batching=5?seed=' . self::PLACEHOLDER];
        yield 'a ; after a number' => [$dsn . '?stream_max_age=900;seed=' . self::PLACEHOLDER];
    }

    /**
     * Typed with a second ? or a ; the seed stays in the value of the option before it, and those errors print the value.
     * The option names here are no credentials, as a credential's name is refused before these errors can happen.
     */
    #[DataProvider('optionsAMistypedQueryCanEndUpIn')]
    public function testASeedTypedIntoAnOptionsValueStaysOutOfTheError(string $dsnWithAPlaceholder): void
    {
        $dsn = str_replace(self::PLACEHOLDER, TestNkeys::seed(), $dsnWithAPlaceholder);

        try {
            (new NatsTransportConfigurationBuilder())->build($dsn);
            self::fail('A value with a query in it was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertMatchesRegularExpression('/^Invalid |must be numeric/', $thrown->getMessage());
            self::assertNoSecretIn($thrown, TestNkeys::seed());
        }
    }

    /**
     * @return iterable<string, array{string, string}> The credential option and the query before it, which is left out of
     *                                                 the provider's values for the seed to stay out of the frames
     */
    public static function credentialsTypedAfterAnotherOption(): iterable
    {
        foreach (['nkey_seed', 'nkey', 'jwt', 'token', 'username', 'password', 'tls_key_passphrase'] as $credential) {
            yield $credential . ' after a second ?' => [$credential, 'consumer=workers?'];
            yield $credential . ' after a ;' => [$credential, 'consumer=workers;'];
            yield $credential . ' after an encoded ?' => [$credential, 'consumer=workers%3F'];
            yield $credential . ' after an encoded ;' => [$credential, 'consumer=workers%3B'];
            yield strtoupper($credential) . ' in capitals' => [strtoupper($credential), 'consumer=workers;'];
        }

        yield 'in a file name, whose error would print it' => ['nkey_seed', 'tls_ca_file=/no/such/ca.pem?'];
        yield 'in a description, which the server would store' => ['nkey_seed', 'stream_description=orders;'];
        yield 'in a list of tags' => ['token', 'stream_placement_tags[]=ssd;'];
        yield 'in a number' => ['nkey_seed', 'batching=5?'];
        yield 'after an option with no value' => ['nkey_seed', 'consumer?'];
        yield 'right after the first ?' => ['nkey_seed', '?'];
        yield 'after a ; and a space' => ['token', 'consumer=workers; '];
        yield 'after a ; and an encoded space' => ['token', 'consumer=workers;%20'];
        yield 'after a ; and a +, which parse_str() reads as a space' => ['token', 'consumer=workers;+'];
        yield 'after a ; and two +' => ['token', 'consumer=workers;++'];
        yield 'after a ; and an encoded tab' => ['token', 'consumer=workers;%09'];
        yield 'after a ; and an encoded line end' => ['token', 'consumer=workers;%0A'];
        yield 'with a space before its =' => ['token ', 'consumer=workers;'];
        yield 'with a +, which parse_str() reads as a space, before its =' => ['token+', 'consumer=workers;'];
        yield 'with two + before its =' => ['token++', 'consumer=workers;'];
        yield 'with an encoded tab before its =' => ['token%09', 'consumer=workers;'];
        yield 'with an encoded line end before its =' => ['token%0A', 'consumer=workers;'];
        yield 'in capitals' => ['TOKEN', 'consumer=workers;'];
        yield 'with a first capital' => ['Nkey_seed', 'consumer=workers?'];
    }

    /**
     * By default parse_str() splits on & only, so such a credential is a part of the option before it, and goes where that
     * goes: into the TLS library's error for a file name, or to the server, which stores it, for a consumer or a
     * description. That is never what was meant, and the login that was meant does not happen either.
     */
    #[DataProvider('credentialsTypedAfterAnotherOption')]
    public function testACredentialTypedAfterASecondQuestionMarkOrASemicolonIsRefusedWithoutRepeatingIt(string $credential, string $before): void
    {
        $dsn = self::DSN_WITHOUT_CREDENTIALS . '?' . $before . $credential . '=' . TestNkeys::seed();

        try {
            (new NatsTransportConfigurationBuilder())->build($dsn);
            self::fail('A credential typed into the value of another option was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertSame(
                'The NATS DSN holds a credential after a second "?" or a ";" in its query, where it would be read as a part '
                . 'of the option before it. Separate the options with "&".',
                $thrown->getMessage(),
            );
            self::assertNoSecretIn($thrown, TestNkeys::seed());
        }
    }

    /**
     * Those are not separators in an option, where nothing is split, and a value that merely looks alike is left alone.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function valuesThatOnlyLookLikeACredentialInAQuery(): iterable
    {
        $dsn = self::DSN_WITHOUT_CREDENTIALS;

        yield 'a description given as an option' => [$dsn, ['stream_description' => 'orders?token=none']];
        yield 'a credential after a proper &' => [$dsn . '?consumer=workers&token=abc', []];
        yield 'a credential after a proper &, with a ? ending the value before it' => [$dsn . '?stream_description=why?&token=abc', []];
        yield 'a credential after a proper &, with a ; ending the value before it' => [$dsn . '?stream_description=why;&token=abc', []];
        yield 'an option that only starts like a credential' => [$dsn . '?stream_description=why;tokens=2', []];
        yield 'an option that only ends like a credential' => [$dsn . '?stream_description=why;mytoken=abc', []];
        yield 'a ; with no option name after it' => [$dsn . '?stream_description=a;b', []];
        yield 'a name with no = after it' => [$dsn . '?stream_description=what?token', []];
        yield 'a space and a name with no = after it' => [$dsn . '?stream_description=what;+token', []];
        yield 'another word after the space' => [$dsn . '?stream_description=why;+mytoken=abc', []];
        yield 'an encoded &, which stays a part of the value' => [$dsn . '?stream_description=a%26token=abc', []];
        yield 'a double encoding, which stays a part of the value' => [$dsn . '?stream_description=a%253Btoken=abc', []];
        yield 'a password with a ; in the DSN, not in the query' => ['nats://admin:a%3Btoken=b@localhost:4222/test-stream/test-topic', []];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('valuesThatOnlyLookLikeACredentialInAQuery')]
    public function testAValueThatOnlyLooksLikeACredentialInAQueryIsAccepted(string $dsn, array $options): void
    {
        self::assertInstanceOf(NatsTransportConfiguration::class, (new NatsTransportConfigurationBuilder())->build($dsn, $options));
    }

    /**
     * The same value in an option, which nothing splits: the frame of the numeric check must not keep it either.
     */
    public function testASeedInTheValueOfANumericOptionStaysOutOfTheFrames(): void
    {
        try {
            (new NatsTransportConfigurationBuilder())->build(self::DSN_WITHOUT_CREDENTIALS, ['batching' => '5?nkey_seed=' . TestNkeys::seed()]);
            self::fail('A value that is not a number was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertSame('The batching option must be numeric.', $thrown->getMessage());
            self::assertNoSecretIn($thrown, TestNkeys::seed());
        }
    }

    /**
     * A plain mistake is what these errors are for, so it is still repeated. A password can be shorter than the limit, but
     * the characters of a query cannot be in a value repeated here.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function plainMistakes(): iterable
    {
        yield 'a stream name' => ['nats://localhost:4222/test*stream/test-topic', 'NATS stream name "test*stream" contains invalid characters.'];
        yield 'a topic name' => ['nats://localhost:4222/test-stream/orders.*', 'NATS topic name "orders.*" contains invalid characters.'];
        yield 'an allowed value' => ['nats://localhost:4222/test-stream/test-topic?retry_handler=natz', "Invalid retry_handler option 'natz'."];
        yield 'stream_storage' => ['nats://localhost:4222/test-stream/test-topic?stream_storage=disk', "Invalid stream_storage option 'disk'."];
        yield 'a boolean' => ['nats://localhost:4222/test-stream/test-topic?stream_deny_delete=perhaps', "Invalid stream_deny_delete option 'perhaps'."];
        yield '50 characters, the longest repeated' => ['nats://localhost:4222/test-stream/test-topic?retry_handler=' . str_repeat('x', 50), "'" . str_repeat('x', 50) . "'"];
    }

    #[DataProvider('plainMistakes')]
    public function testAPlainMistakeIsRepeatedInTheError(string $dsn, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        (new NatsTransportConfigurationBuilder())->build($dsn);
    }

    /**
     * A credential can be shorter than the limit, so it is the pattern that keeps one typed into a value out of the message.
     * None of the characters that separate a query may be repeated, in any value that gets to an error of its own.
     *
     * @return iterable<string, array{string}>
     */
    public static function shortValuesWithACharacterOfAQuery(): iterable
    {
        yield 'a ? and an =' => ['nats?seed=abc'];
        yield 'a ; and an =' => ['nats;seed=abc'];
        yield 'an =' => ['seed=abc'];
        yield 'an encoded &' => ['a%26b'];
        yield 'a ?' => ['a?b'];
        yield 'a ;' => ['a;b'];
    }

    #[DataProvider('shortValuesWithACharacterOfAQuery')]
    public function testAShortValueWithACharacterOfAQueryIsNotRepeated(string $value): void
    {
        try {
            (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/test-stream/test-topic?retry_handler=' . $value);
            self::fail('An unknown retry handler was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringStartsWith('Invalid retry_handler option. Allowed values are', $thrown->getMessage());
        }
    }

    /**
     * The same for a name in the DSN's path, where a query typed with & instead of ? ends up.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function shortNamesWithACharacterOfAQuery(): iterable
    {
        yield 'a stream' => ['nats://localhost:4222/orders&seed=abc/test-topic', 'NATS stream name contains invalid characters.'];
        yield 'a topic' => ['nats://localhost:4222/test-stream/orders&seed=abc', 'NATS topic name contains invalid characters.'];
    }

    #[DataProvider('shortNamesWithACharacterOfAQuery')]
    public function testAShortNameWithACharacterOfAQueryIsNotRepeated(string $dsn, string $expectedStart): void
    {
        try {
            (new NatsTransportConfigurationBuilder())->build($dsn);
            self::fail('An invalid name was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringStartsWith($expectedStart, $thrown->getMessage());
        }
    }

    /**
     * The end of the pattern must not let a last newline through, as "natz\n" is not a plain value.
     */
    public function testAValueEndingInANewlineIsNotRepeated(): void
    {
        try {
            (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/test-stream/test-topic?retry_handler=natz%0A');
            self::fail('An unknown retry handler was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringStartsWith('Invalid retry_handler option. Allowed values are', $thrown->getMessage());
        }
    }

    /**
     * A list has no text to repeat, and a word in its place would read as if it were the value.
     */
    public function testAListWhereABooleanBelongsIsNotShownAsAValue(): void
    {
        try {
            (new NatsTransportConfigurationBuilder())->build(self::DSN_WITHOUT_CREDENTIALS, ['stream_deny_delete' => ['x']]);
            self::fail('A list was accepted as a boolean.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringStartsWith('Invalid stream_deny_delete option. Expected a boolean', $thrown->getMessage());
        }
    }

    public function testAValueLongerThanTheLimitIsNotRepeated(): void
    {
        $tooLong = str_repeat('x', 51);

        try {
            (new NatsTransportConfigurationBuilder())->build('nats://localhost:4222/test-stream/test-topic?retry_handler=' . $tooLong);
            self::fail('An unknown retry handler was accepted.');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringStartsWith('Invalid retry_handler option. Allowed values are', $thrown->getMessage());
        }
    }

    /**
     * @return array<string, array{string, array<string, mixed>}> The DSN and the options of a build that fails at the
     *                                                            place in the key.
     */
    private static function dsnAndOptionsOfTheFailures(): array
    {
        $dsn = self::DSN_WITHOUT_CREDENTIALS;

        return [
            'parseDsn' => ['nats:///test-stream/test-topic', []],
            'parseStreamAndTopic' => ['nats://localhost:4222', []],
            'buildConfiguration' => [$dsn, ['retry_handler' => 'nope']],
            'assertPositiveNumber' => [$dsn, ['batching' => 0]],
            'assertNonNegativeNumber' => [$dsn, ['nak_delay' => -1]],
            'toNumber' => [$dsn, ['batching' => 'many']],
            'assertNotExceedingInt32' => [$dsn, ['stream_max_message_size' => 2147483648]],
            // Past the range of an int this used to wrap around to a negative number and fail in the client's constructor,
            // whose frame holds every credential.
            'assertNumberFitsAnInt' => [$dsn, ['max_reconnect_attempts' => 1e19]],
            'assertStreamDescriptionLength' => [$dsn, ['stream_description' => str_repeat('x', 4097)]],
            'assertTriStateBooleans' => [$dsn, ['stream_deny_delete' => 'maybe']],
            'normalizeStreamPlacement' => [$dsn, ['stream_placement_cluster' => ' ']],
            'assertBackoff' => [$dsn, ['backoff' => 'soon']],
            'assertMaxDeliverExceedsBackoff' => [$dsn, ['max_deliver' => 1, 'backoff' => [1, 2]]],
            'assertDuplicateWindowNotExceedingMaxAge' => [$dsn, ['stream_max_age' => 1, 'stream_duplicate_window' => 2]],
            'assertResendOnReconnect' => [$dsn, ['resend_on_reconnect' => true]],
            'normalizeStorageBackend' => [$dsn, ['stream_storage' => 'paper']],
            'normalizeStreamRetention' => [$dsn, ['stream_retention' => 'forever']],
            'normalizeStreamDiscard' => [$dsn, ['stream_discard' => 'nope']],
            'normalizeStreamCompression' => [$dsn, ['stream_compression' => 'nope']],
            'normalizeReplayPolicy' => [$dsn, ['replay_policy' => 'nope']],
        ];
    }

    /**
     * @return list<string>
     */
    private static function secretForms(): array
    {
        return ['seed option', 'seed in the DSN query', 'password option', 'password in the DSN'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{string, array<string, mixed>} The DSN and the options with the secret in them, in the given form
     */
    private static function withSecret(string $form, string $dsn, array $options, ?string $seed = null): array
    {
        $seed ??= TestNkeys::seed();

        return match ($form) {
            'seed option' => [$dsn, $options + ['nkey_seed' => $seed]],
            'seed in the DSN query' => [$dsn . '?nkey_seed=' . $seed, $options],
            'password option' => [$dsn, $options + ['password' => self::PASSWORD]],
            'password in the DSN' => [str_replace('nats://', 'nats://admin:' . self::PASSWORD . '@', $dsn), $options],
            default => self::fail('Unknown form of a secret: ' . $form),
        };
    }

    private static function clientOptionsOf(NatsTransportConfiguration $configuration): NatsOptions
    {
        $connection = (new \ReflectionClass($configuration->client))->getProperty('connection')->getValue($configuration->client);
        self::assertIsObject($connection);
        $options = (new \ReflectionClass($connection))->getProperty('options')->getValue($connection);
        self::assertInstanceOf(NatsOptions::class, $options);

        return $options;
    }

    private static function secretOf(string $form): string
    {
        return str_starts_with($form, 'seed') ? TestNkeys::seed() : self::PASSWORD;
    }

    /**
     * Fails if the secret, or the start of it that the string form of an exception shows, is in the message or the string
     * form of the exception, or in the frames of this package and the client, in the whole chain of previous exceptions.
     */
    private static function assertNoSecretIn(Throwable $failure, string $secret): void
    {
        for ($exception = $failure; $exception !== null; $exception = $exception->getPrevious()) {
            $printed = $exception->getMessage() . "\n" . $exception . "\n" . self::printedFrames($exception);
            self::assertStringNotContainsString($secret, $printed);
            self::assertStringNotContainsString(substr($secret, 0, 15), $printed);
        }
    }

    /**
     * The frames of this package and the client, printed with their arguments. The other frames are left out: printed in
     * full they reach the test runner's whole test suite, with the seeds in other tests' data.
     */
    private static function printedFrames(Throwable $exception): string
    {
        $frames = array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'IDCT\\') && !str_contains($frame['class'] ?? '', '\\Tests\\'),
        );
        self::assertNotSame([], $frames, 'The test looks at no frame.');

        return print_r($frames, true);
    }
}
