<?php

declare(strict_types=1);

/*
 * Checks that the Symfony Messenger installed in the current directory rejects a message it sent for retry, as
 * releases before 7.4.22 and 8.1.10 do. Later releases acknowledge it instead (symfony/symfony#67038), and the
 * transport settles both. CI's "lowest" leg runs the unit tests and the functional test app on the lowest release
 * each allows so that the reject() path has tests; this fails when that release no longer takes it, which a raised
 * requirement would otherwise change without a word.
 *
 * Run from the directory whose vendor/ is meant: the project root, or tests/functional.
 */

$autoload = getcwd() . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "No vendor/autoload.php in " . getcwd() . ": run composer install first.\n");
    exit(1);
}

require $autoload;

$version = (string) Composer\InstalledVersions::getVersion('symfony/messenger');
$pretty = (string) Composer\InstalledVersions::getPrettyVersion('symfony/messenger');
$rejects = version_compare($version, '7.4.22', '<')
    || (version_compare($version, '8.0.0', '>=') && version_compare($version, '8.1.10', '<'));

if (!$rejects) {
    fwrite(STDERR, "Symfony Messenger $pretty acknowledges a message it sent for retry: this leg no longer runs the reject() path.\n");
    exit(1);
}

echo "Symfony Messenger $pretty rejects a message it sent for retry.\n";
