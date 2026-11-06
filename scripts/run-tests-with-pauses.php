<?php

declare(strict_types=1);

/*
 * Runs the unit tests in `composer test:unit:paused`, stopping the process at random moments, for 250 ms each time, as
 * a machine short of CPU does. A test that depends on how fast this process runs, through a short budget or a bound on
 * how long something took, fails here nearly every time, where it would fail only now and then on a busy machine. It
 * loads nothing: a stopped process uses no CPU.
 *
 * Arguments go to PHPUnit, such as --filter. TEST_PAUSE_MS sets the length of a pause. The exit code is PHPUnit's. Needs
 * the posix and pcntl extensions. Run from the project root, where composer runs it.
 */

const DEFAULT_PAUSE_MS = 250;
const SHORTEST_RUN_MS = 20;
const LONGEST_RUN_MS = 120;

if (!function_exists('posix_kill') || !defined('SIGSTOP')) {
    fwrite(STDERR, "Pausing the tests needs the posix and pcntl extensions.\n");
    exit(1);
}

$pauseMs = (int) (getenv('TEST_PAUSE_MS') ?: DEFAULT_PAUSE_MS);

$process = proc_open(
    [PHP_BINARY, 'vendor/bin/phpunit', '--configuration', 'phpunit.xml.dist', ...array_slice($argv, 1)],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
);
if (!is_resource($process)) {
    fwrite(STDERR, 'Could not start ' . PHP_BINARY . ".\n");
    exit(1);
}

$pid = proc_get_status($process)['pid'];
$pauses = 0;
do {
    usleep(random_int(SHORTEST_RUN_MS, LONGEST_RUN_MS) * 1000);
    $status = proc_get_status($process);
    if ($status['running']) {
        posix_kill($pid, SIGSTOP);
        usleep($pauseMs * 1000);
        posix_kill($pid, SIGCONT);
        $pauses++;
    }
} while ($status['running']);
proc_close($process);

printf("Paused the tests %d times, for %d ms each.\n", $pauses, $pauseMs);
exit($status['exitcode']);
