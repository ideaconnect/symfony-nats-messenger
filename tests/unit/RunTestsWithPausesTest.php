<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * scripts/run-tests-with-pauses.php guards the unit tests against depending on how fast their process runs, in CI. A
 * script that stopped pausing would keep passing, and guard nothing, so what it does is checked here.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class RunTestsWithPausesTest extends TestCase
{
    /**
     * This test holds a dial for 150 ms, longer than the script ever lets the tests run between two pauses, so at
     * least one pause lands in it.
     */
    public function testTheTestsArePausedAndTheirArgumentsReachPhpunit(): void
    {
        $result = self::runScript('--filter', 'testAPullThatRunsIntoTheLostConnectionEndsEmptyAndTheNextOneDelivers');

        self::assertSame(0, $result['exitCode'], PhpProcess::describe($result));
        self::assertStringContainsString('OK (1 test', $result['stdout']);
        self::assertMatchesRegularExpression('/Paused the tests [1-9]\d* times, for 1 ms each\./', $result['stdout']);
    }

    /** A failed run must fail the CI step: PHPUnit's exit code is the script's. */
    public function testThePhpunitExitCodeIsTheScripts(): void
    {
        $result = self::runScript('--no-such-option');

        self::assertSame(2, $result['exitCode'], PhpProcess::describe($result));
    }

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private static function runScript(string ...$arguments): array
    {
        putenv('TEST_PAUSE_MS=1');
        try {
            return PhpProcess::run(['scripts/run-tests-with-pauses.php', ...$arguments], dirname(__DIR__, 2));
        } finally {
            putenv('TEST_PAUSE_MS');
        }
    }
}
