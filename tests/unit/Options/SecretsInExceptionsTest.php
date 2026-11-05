<?php

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * What a failed build leaves in its exception, with exception arguments on: zend.exception_ignore_args=0 is PHP's
 * default without a php.ini and what the official php:8.x-cli images run, and tools that record stack frames read them.
 *
 * Each test keeps the secret out of its own frames: PHP records the arguments of every frame down to the test runner, so
 * the secrets are not passed as arguments here, and no argument is a closure that captures them.
 */
final class SecretsInExceptionsTest extends TestCase
{
    private const DSN_WITHOUT_CREDENTIALS = 'nats://localhost:4222/test-stream/test-topic';

    /**
     * A seed with its last character changed fails the client's checksum. Its message is safe to pass on, but the client's
     * exception is not: its frames hold the whole seed as a string argument, and one wrong character is easily repaired.
     */
    #[RequiresPhpExtension('sodium')]
    public function testAMistypedSeedStaysOutOfTheFailure(): void
    {
        $mistyped = substr(TestNkeys::seed(), 0, -1) . 'B';

        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            (new NatsTransportConfigurationBuilder())->build(self::DSN_WITHOUT_CREDENTIALS . '?nkey_seed=' . $mistyped);
            self::fail('A mistyped seed was accepted.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringStartsWith('The NKey seed cannot be used: ', $failure->getMessage());
            self::assertNoSecretIn($failure, $mistyped);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    /**
     * Fails if the secret, or the start of it that the string form of an exception shows, is in the message or the string
     * form of the exception, or in the frames of this package and the client, in the whole chain of previous exceptions.
     * The other frames are left out of that last check: printed in full they reach the test runner's whole test suite,
     * with the seeds in other tests' data.
     */
    private static function assertNoSecretIn(Throwable $failure, string $secret): void
    {
        for ($exception = $failure; $exception !== null; $exception = $exception->getPrevious()) {
            $frames = array_filter(
                $exception->getTrace(),
                static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'IDCT\\') && !str_contains($frame['class'] ?? '', '\\Tests\\'),
            );
            self::assertNotSame([], $frames, 'The test looks at no frame: are exception arguments off?');

            $printed = $exception->getMessage() . "\n" . $exception . "\n" . print_r($frames, true);
            self::assertStringNotContainsString($secret, $printed);
            self::assertStringNotContainsString(substr($secret, 0, 15), $printed);
        }
    }
}
