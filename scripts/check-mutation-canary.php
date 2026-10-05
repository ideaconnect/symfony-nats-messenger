<?php

declare(strict_types=1);

/*
 * Checks the summary of the Infection run in `composer test:mutation:canary`. That run uses --noop, so its
 * mutants change nothing and every one of them must escape. A mutant detected anyway shows that the mutant
 * processes do not run the tests the way a plain run does, as when the client's final classes could not be
 * doubled there and every mutant counted as killed. A missing or malformed summary fails as well, so that a
 * run that wrote nothing, or a summary format that changed, cannot pass. Arguments are ignored: Composer
 * appends the arguments of a script call to every command of the script.
 *
 * Run from the project root, where composer runs it.
 */

const SUMMARY_FILE = '.infection/noop-summary.json';

function fail(string $reason): never
{
    fwrite(STDERR, 'Mutation canary failed: ' . $reason . "\n");
    exit(1);
}

$json = is_file(SUMMARY_FILE) ? file_get_contents(SUMMARY_FILE) : false;
if ($json === false) {
    fail(SUMMARY_FILE . ' is missing: run composer test:mutation:canary, which writes it.');
}

$summary = json_decode($json, true);
$stats = is_array($summary) ? ($summary['stats'] ?? null) : null;
if (!is_array($stats)) {
    fail(SUMMARY_FILE . ' has no "stats" object.');
}

$counts = [];
foreach (['totalMutantsCount', 'killedCount', 'escapedCount', 'errorCount', 'syntaxErrorCount', 'timeOutCount'] as $key) {
    $count = $stats[$key] ?? null;
    if (!is_int($count)) {
        fail(SUMMARY_FILE . " has no whole number for \"{$key}\".");
    }

    $counts[$key] = $count;
}

$detected = $counts['killedCount'] + $counts['errorCount'] + $counts['syntaxErrorCount'] + $counts['timeOutCount'];
printf(
    "Mutants that change nothing: %d, escaped: %d, detected: %d\n",
    $counts['totalMutantsCount'],
    $counts['escapedCount'],
    $detected,
);

if ($detected > 0) {
    fail('mutants that change nothing were detected, so the mutant processes do not run the tests the way a plain run does. infection.log shows what failed in them.');
}

if ($counts['escapedCount'] === 0) {
    fail('no mutant ran, so the run shows nothing.');
}

echo "Every mutant that changes nothing escaped.\n";
