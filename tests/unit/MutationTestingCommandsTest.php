<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Infection runs defined in composer.json, which CI runs for every push and pull request: the canary, whose
 * mutants change nothing and all escape by design, and the real run, whose survivors docs/TESTS.md lists.
 */
final class MutationTestingCommandsTest extends TestCase
{
    /**
     * On GitHub Actions Infection turns every escaped mutant into a warning annotation unless it is told not to.
     * For these runs the annotations would mark the same source lines on every run, in every pull request, as if
     * the change under review had let the mutants escape.
     */
    public function testInfectionRunsWriteNoGitHubAnnotations(): void
    {
        $commands = self::infectionCommands();

        self::assertArrayHasKey('test:mutation', $commands);
        self::assertArrayHasKey('test:mutation:canary', $commands);
        foreach ($commands as $script => $arguments) {
            self::assertContains('--logger-github=false', $arguments, "composer {$script} would annotate escaped mutants on GitHub.");
        }
    }

    /**
     * Without annotations the job log is where a run shows its escaped mutants, and Infection lists only the
     * first 20 of them unless it is told otherwise.
     */
    public function testMutationRunListsEveryEscapedMutant(): void
    {
        self::assertContains('--show-mutations=max', self::infectionCommands()['test:mutation'] ?? []);
    }

    /**
     * The arguments of each command in composer.json's scripts that runs Infection, by script name.
     *
     * @return array<string, list<string>>
     */
    private static function infectionCommands(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

        $commands = [];
        foreach ($scripts as $name => $script) {
            foreach (is_array($script) ? $script : [$script] as $command) {
                $arguments = is_string($command) ? preg_split('/\s+/', trim($command)) : false;
                if ($arguments !== false && in_array('./vendor/bin/infection', $arguments, true)) {
                    $commands[(string) $name] = $arguments;
                }
            }
        }

        return $commands;
    }
}
