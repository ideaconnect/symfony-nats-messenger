<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\Tests\Support\PhpProcess;
use PHPUnit\Framework\TestCase;

/**
 * Infection runs each mutant in a PHPUnit process whose bootstrap it generates: it puts Infection's
 * IncludeInterceptor on file://, so that including the original source file loads the mutated copy, and then
 * requires tests/bootstrap.php (Infection's MutationConfigBuilder). These tests start PHP the same way and check
 * that the unit tests run there as they do in a plain run: the client's final classes can be doubled, and the
 * mutated file is the one that is loaded. If the classes cannot be doubled, every test that doubles one fails,
 * and Infection counts each mutant those tests cover as killed, whatever the tests check.
 */
final class MutationTestingBootstrapTest extends TestCase
{
    private const INTERCEPTOR_FILE = 'vendor/infection/include-interceptor/src/IncludeInterceptor.php';

    private const INTERCEPTOR_CLASS = 'Infection\StreamWrapper\IncludeInterceptor';

    /** The prefix Infection's PHAR build puts in front of its namespaces, interceptor included. */
    private const PHAR_PREFIX = '_HumbugBoxMutationTest';

    private const FIXTURE_CLASS = 'IDCT\NatsMessenger\Tests\MutationFixture\Fixture';

    private string $directory = '';

    protected function setUp(): void
    {
        if (!is_file(self::root() . '/' . self::INTERCEPTOR_FILE)) {
            self::markTestSkipped('infection/include-interceptor is not installed.');
        }

        $directory = sys_get_temp_dir() . '/nats-messenger-mutation-' . bin2hex(random_bytes(6));
        mkdir($directory);
        // Infection gives the interceptor the real path of the original file, so resolve any symlink here too.
        $this->directory = (string) realpath($directory);
    }

    protected function tearDown(): void
    {
        if ($this->directory === '') {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testMutantProcessCanDoubleFinalClassesAndLoadsTheMutatedFile(): void
    {
        $result = $this->runMutantProcess(self::root() . '/' . self::INTERCEPTOR_FILE, self::INTERCEPTOR_CLASS);

        self::assertFalse($result['jetStreamContextIsFinal'], 'BypassFinals is not active in the mutant process.');
        self::assertFalse($result['fixtureIsFinal'], 'BypassFinals did not process the mutated file.');
        self::assertSame('mutant', $result['fixtureValue'], 'The original file was loaded instead of the mutated one.');
    }

    /**
     * Infection's PHAR prefixes the namespace of the interceptor, as of all its classes, so it is not found by
     * its plain class name.
     */
    public function testMutantProcessOfInfectionsPharCanDoubleFinalClassesAndLoadsTheMutatedFile(): void
    {
        $source = (string) file_get_contents(self::root() . '/' . self::INTERCEPTOR_FILE);
        $prefixed = str_replace(
            'namespace Infection\StreamWrapper;',
            'namespace ' . self::PHAR_PREFIX . '\Infection\StreamWrapper;',
            $source,
            $replaced,
        );
        self::assertSame(1, $replaced, 'The namespace line of the interceptor changed: update this test.');
        $interceptorFile = $this->directory . '/PrefixedIncludeInterceptor.php';
        file_put_contents($interceptorFile, $prefixed);

        $result = $this->runMutantProcess($interceptorFile, self::PHAR_PREFIX . '\\' . self::INTERCEPTOR_CLASS);

        self::assertFalse($result['jetStreamContextIsFinal'], 'BypassFinals is not active in the mutant process.');
        self::assertFalse($result['fixtureIsFinal'], 'BypassFinals did not process the mutated file.');
        self::assertSame('mutant', $result['fixtureValue'], 'The original file was loaded instead of the mutated one.');
    }

    /**
     * PHPUnit requires vendor/autoload.php before the bootstrap Infection generates, so a source file that
     * Composer loads up front, a "files" entry, is loaded unmutated before the interceptor is on. Requiring the
     * mutated copy on top of it would end the process with a fatal redeclaration, which Infection counts as a
     * detected mutant. The process keeps running the original code, and Infection reports the mutant as escaped.
     */
    public function testSourceFileLoadedBeforeInfectionsBootstrapIsNotLoadedAgain(): void
    {
        $result = $this->runMutantProcess(self::root() . '/' . self::INTERCEPTOR_FILE, self::INTERCEPTOR_CLASS, true);

        self::assertFalse($result['jetStreamContextIsFinal'], 'BypassFinals is not active in the mutant process.');
        self::assertSame('original', $result['fixtureValue'], 'The file loaded before the bootstrap no longer runs its own code.');
    }

    /**
     * Starts PHP the way a mutant process starts: vendor/autoload.php first, which PHPUnit requires before any
     * bootstrap, then the statements of the bootstrap Infection generates, which ends by requiring
     * tests/bootstrap.php. Reports what the process sees. With $loadOriginalFirst the original file is loaded
     * right after the autoloader, as a Composer "files" entry would be.
     *
     * @return array{jetStreamContextIsFinal: bool, fixtureIsFinal: bool, fixtureValue: string}
     */
    private function runMutantProcess(string $interceptorFile, string $interceptorClass, bool $loadOriginalFirst = false): array
    {
        $original = $this->directory . '/Fixture.php';
        $mutant = $this->directory . '/mutant.infection.php';
        $script = $this->directory . '/process.php';
        file_put_contents($original, self::fixture('original'));
        file_put_contents($mutant, self::fixture('mutant'));

        file_put_contents($script, sprintf(
            <<<'PHP'
                <?php
                require %8$s;
                if (%5$s) {
                    require %3$s;
                }

                require_once %1$s;
                \%2$s::intercept(%3$s, %4$s);
                \%2$s::enable();
                require_once %6$s;

                spl_autoload_register(static function (string $class): void {
                    if ($class === %7$s) {
                        require %3$s;
                    }
                });

                $fixture = %7$s;
                echo json_encode([
                    'jetStreamContextIsFinal' => (new ReflectionClass(\IDCT\NATS\JetStream\JetStreamContext::class))->isFinal(),
                    'fixtureIsFinal' => (new ReflectionClass($fixture))->isFinal(),
                    'fixtureValue' => $fixture::value(),
                ]);
                PHP,
            var_export($interceptorFile, true),
            $interceptorClass,
            var_export($original, true),
            var_export($mutant, true),
            var_export($loadOriginalFirst, true),
            var_export(self::root() . '/tests/bootstrap.php', true),
            var_export(self::FIXTURE_CLASS, true),
            var_export(self::root() . '/vendor/autoload.php', true),
        ));

        $process = PhpProcess::run([$script], $this->directory);
        self::assertSame(0, $process['exitCode'], PhpProcess::describe($process));

        $result = json_decode($process['stdout'], true);
        self::assertIsArray($result, PhpProcess::describe($process));
        self::assertIsBool($result['jetStreamContextIsFinal'] ?? null, PhpProcess::describe($process));
        self::assertIsBool($result['fixtureIsFinal'] ?? null, PhpProcess::describe($process));
        self::assertIsString($result['fixtureValue'] ?? null, PhpProcess::describe($process));

        return $result;
    }

    private static function fixture(string $value): string
    {
        return <<<PHP
            <?php

            namespace IDCT\\NatsMessenger\\Tests\\MutationFixture;

            final class Fixture
            {
                public static function value(): string
                {
                    return '{$value}';
                }
            }

            PHP;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
