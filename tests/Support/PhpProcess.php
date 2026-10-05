<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use RuntimeException;

/**
 * Runs a PHP script in a child process, for tests of what happens outside the test runner's own process: the
 * processes Infection runs mutants in, and the scripts Composer runs around them.
 */
final class PhpProcess
{
    /**
     * Runs PHP_BINARY with the arguments in the working directory, without a shell. PHP's own errors go to
     * stderr, so that stdout holds only what the script prints.
     *
     * @param list<string> $arguments
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(array $arguments, string $workingDirectory): array
    {
        $stderrFile = tempnam(sys_get_temp_dir(), 'nats-messenger-stderr-');
        if ($stderrFile === false) {
            throw new RuntimeException('Could not create a file for the stderr of the child process.');
        }

        try {
            // stderr goes to a file rather than a pipe, so that a child writing a lot to it cannot block while
            // stdout is read.
            $process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', ...$arguments],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
                $workingDirectory,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start ' . PHP_BINARY . '.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exitCode = proc_close($process);

            return [
                'exitCode' => $exitCode,
                'stdout' => $stdout === false ? '' : $stdout,
                'stderr' => (string) file_get_contents($stderrFile),
            ];
        } finally {
            unlink($stderrFile);
        }
    }

    /**
     * Describes a finished process for an assertion message.
     *
     * @param array{exitCode: int, stdout: string, stderr: string} $result
     */
    public static function describe(array $result): string
    {
        return sprintf("exit code %d\nstdout:\n%s\nstderr:\n%s", $result['exitCode'], $result['stdout'], $result['stderr']);
    }
}
