<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two scripts CI runs around the mutation run: scripts/check-mutation-canary.php reads the summary of an
 * Infection run whose mutants change nothing, and scripts/check-mutation-log.php reads infection.log after the
 * real run. They guard the mutation score, so they fail when they cannot read what they check, and they ignore
 * the arguments Composer appends to every command of a script.
 */
final class MutationTestingChecksTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nats-messenger-checks-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/.infection', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.infection/noop-summary.json', '/infection.log'] as $file) {
            if (is_file($this->directory . $file)) {
                unlink($this->directory . $file);
            }
        }
        rmdir($this->directory . '/.infection');
        rmdir($this->directory);
    }

    public function testCanaryPassesWhenEveryMutantEscaped(): void
    {
        $this->writeSummary(['stats' => self::stats()]);

        $result = $this->runScript('check-mutation-canary.php');

        self::assertSame(0, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString('Mutants that change nothing: 46, escaped: 46, detected: 0', $result['stdout']);
    }

    /**
     * @param array<string, mixed> $stats
     */
    #[DataProvider('statsTheCanaryRejects')]
    public function testCanaryFailsWhenAMutantWasDetectedOrNoneRan(array $stats, string $reason): void
    {
        $this->writeSummary(['stats' => $stats]);

        $result = $this->runScript('check-mutation-canary.php');

        self::assertSame(1, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString($reason, $result['stderr']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function statsTheCanaryRejects(): iterable
    {
        yield 'a killed mutant' => [self::stats(['killedCount' => 1, 'escapedCount' => 45]), 'were detected'];
        yield 'an errored mutant' => [self::stats(['errorCount' => 1, 'escapedCount' => 45]), 'were detected'];
        yield 'a syntax error' => [self::stats(['syntaxErrorCount' => 1, 'escapedCount' => 45]), 'were detected'];
        yield 'a timed out mutant' => [self::stats(['timeOutCount' => 1, 'escapedCount' => 45]), 'were detected'];
        yield 'no mutant' => [self::stats(['totalMutantsCount' => 0, 'escapedCount' => 0]), 'no mutant ran'];
        yield 'a count given as a string' => [self::stats(['killedCount' => '0']), 'no whole number for "killedCount"'];
        yield 'a missing count' => [array_diff_key(self::stats(), ['errorCount' => true]), 'no whole number for "errorCount"'];
    }

    /**
     * A summary that is missing, cut off or in another format fails instead of reading as nothing detected.
     */
    #[DataProvider('summariesTheCanaryCannotRead')]
    public function testCanaryFailsWhenItCannotReadTheSummary(?string $contents, string $reason): void
    {
        if ($contents !== null) {
            file_put_contents($this->directory . '/.infection/noop-summary.json', $contents);
        }

        $result = $this->runScript('check-mutation-canary.php');

        self::assertSame(1, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString($reason, $result['stderr']);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function summariesTheCanaryCannotRead(): iterable
    {
        yield 'missing' => [null, 'is missing'];
        yield 'cut off' => ['{"stats": {"totalMutantsCount": 46,', 'has no "stats" object'];
        yield 'counts under another key' => ['{"statistics": {"killedCount": 42}}', 'has no "stats" object'];
    }

    /**
     * Composer appends the arguments of `composer test:mutation:canary -- ...` to every command of the script.
     */
    public function testCanaryIgnoresArguments(): void
    {
        $this->writeSummary(['stats' => self::stats(['killedCount' => 1, 'escapedCount' => 45])]);

        $result = $this->runScript('check-mutation-canary.php', ['--filter=src/TypeCoercion.php']);

        self::assertSame(1, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString('were detected', $result['stderr']);
    }

    public function testLogCheckPassesWhenNoMutantWasKilledByTheHarness(): void
    {
        file_put_contents($this->directory . '/infection.log', self::log('Failed asserting that two strings are identical.'));

        $result = $this->runScript('check-mutation-log.php');

        self::assertSame(0, $result['exitCode'], PhpProcess::describe($result));
    }

    #[DataProvider('harnessFailures')]
    public function testLogCheckFailsWhenAMutantWasKilledByTheHarness(string $line): void
    {
        file_put_contents($this->directory . '/infection.log', self::log($line));

        $result = $this->runScript('check-mutation-log.php');

        self::assertSame(1, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString('infection.log:6: ' . $line, $result['stderr']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harnessFailures(): iterable
    {
        yield 'a final class that could not be doubled' => ['PHPUnit\Framework\MockObject\Generator\ClassIsFinalException: Class "IDCT\NATS\JetStream\JetStreamContext" is declared "final" and cannot be doubled'];
        yield 'a class that could not extend a final one' => ['PHP Fatal error:  Class IgbinarySerializer@anonymous cannot extend final class IDCT\NatsMessenger\Serializer\IgbinarySerializer'];
        yield 'a failed bootstrap' => ['Error in bootstrap script: ReflectionException: Property does not exist'];
    }

    /**
     * Without a log the check has nothing to go on, which must not read as a clean run.
     */
    #[DataProvider('logsTheCheckCannotRead')]
    public function testLogCheckFailsWhenTheLogIsMissingOrEmpty(?string $contents): void
    {
        if ($contents !== null) {
            file_put_contents($this->directory . '/infection.log', $contents);
        }

        $result = $this->runScript('check-mutation-log.php');

        self::assertSame(1, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString('infection.log is missing or empty', $result['stderr']);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function logsTheCheckCannotRead(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
    }

    public function testLogCheckIgnoresArguments(): void
    {
        file_put_contents($this->directory . '/infection.log', self::log('Error in bootstrap script: Error'));

        $result = $this->runScript('check-mutation-log.php', ['--filter=src/TypeCoercion.php']);

        self::assertSame(1, $result['exitCode'], PhpProcess::describe($result));
    }

    /**
     * The counts of a summary Infection writes with --logger-summary-json, for 46 mutants that all escaped.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function stats(array $overrides = []): array
    {
        return $overrides + [
            'totalMutantsCount' => 46,
            'killedCount' => 0,
            'notCoveredCount' => 0,
            'escapedCount' => 46,
            'errorCount' => 0,
            'syntaxErrorCount' => 0,
            'skippedCount' => 0,
            'ignoredCount' => 0,
            'timeOutCount' => 0,
            'msi' => 0,
            'mutationCodeCoverage' => 100,
            'coveredCodeMsi' => 0,
        ];
    }

    /**
     * An infection.log written with --log-verbosity=all, with one killed mutant whose output has $line on line 6.
     */
    private static function log(string $line): string
    {
        return implode("\n", [
            'Killed by Test Framework mutants:',
            '=================================',
            '',
            '1) src/TypeCoercion.php:126    [M] RoundingFamily [ID] 0123456789abcdef',
            '',
            $line,
            '',
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function writeSummary(array $summary): void
    {
        file_put_contents($this->directory . '/.infection/noop-summary.json', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runScript(string $script, array $arguments = []): array
    {
        return PhpProcess::run([dirname(__DIR__, 2) . '/scripts/' . $script, ...$arguments], $this->directory);
    }
}
