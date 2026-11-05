<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The scripts of composer.json that the README, CLAUDE.md and HUMANS.md tell people to run.
 */
final class ComposerScriptsTest extends TestCase
{
    /**
     * The scenarios take about as long as Composer's limit of 600 seconds for a script. Past it Composer fails the script and
     * leaves Behat running, with the worker it started and the transport's config file behind, in the way of the next run.
     * CI does not notice: setup-php sets COMPOSER_PROCESS_TIMEOUT to 0. CI's two test jobs run this script, so with the Behat
     * command gone they would stay green with no scenario run.
     */
    public function testTheFunctionalSuiteHasNoProcessTimeout(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $script = $scripts['test:functional'] ?? null;

        self::assertIsArray($script);
        self::assertSame('Composer\\Config::disableProcessTimeout', $script[0] ?? null);
        self::assertContains('cd tests/functional && vendor/bin/behat', $script);
    }
}
