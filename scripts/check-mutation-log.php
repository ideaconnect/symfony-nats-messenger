<?php

declare(strict_types=1);

/*
 * Checks infection.log after `composer test:mutation` for mutants that the test harness killed rather than the
 * tests: a double of a final class that could not be created, a class that could not extend a final one, or a
 * bootstrap that failed. Each of these fails every test that gets that far, so Infection counts the mutant as
 * killed although no assertion looked at the mutated code. The log holds the output of every mutant because
 * test:mutation runs with --log-verbosity=all. A missing or empty log fails as well. Arguments are ignored:
 * Composer appends the arguments of a script call to every command of the script.
 *
 * Run from the project root, where composer runs it.
 */

const LOG_FILE = 'infection.log';

const SIGNATURES = ['ClassIsFinalException', 'cannot extend final class', 'Error in bootstrap script'];

$log = is_file(LOG_FILE) ? file_get_contents(LOG_FILE) : false;
if ($log === false || $log === '') {
    fwrite(STDERR, LOG_FILE . " is missing or empty: run composer test:mutation first.\n");
    exit(1);
}

$found = [];
foreach (preg_split('/\R/', $log) ?: [] as $number => $line) {
    foreach (SIGNATURES as $signature) {
        if (str_contains($line, $signature)) {
            $found[] = sprintf('%s:%d: %s', LOG_FILE, $number + 1, trim($line));

            break;
        }
    }
}

if ($found !== []) {
    fwrite(STDERR, sprintf(
        "%d lines of %s show mutants killed by the test harness, not by the tests. The first ones:\n%s\n",
        count($found),
        LOG_FILE,
        implode("\n", array_slice($found, 0, 10)),
    ));
    exit(1);
}

echo 'No mutant in ' . LOG_FILE . " was killed by the test harness.\n";
