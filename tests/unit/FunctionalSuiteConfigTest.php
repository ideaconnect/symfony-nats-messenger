<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use Behat\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * The functional suite's Behat configuration, tests/functional/behat.php. Behat 4 registers step definitions
 * from attributes only, so a step written the Behat 3 way, as a doc-comment annotation, is not registered and
 * every scenario that uses it is reported as undefined. Without strict result interpretation such a run, in CI
 * or through `composer test:functional`, still exits 0.
 */
final class FunctionalSuiteConfigTest extends TestCase
{
    private const BEHAT_CONFIG = __DIR__ . '/../functional/behat.php';

    public function testUndefinedAndPendingStepsFailTheFunctionalSuite(): void
    {
        $config = require self::BEHAT_CONFIG;
        $this->assertInstanceOf(Config::class, $config);

        $profile = $config->toArray()['default'] ?? null;
        $this->assertIsArray($profile);
        $this->assertTrue($profile['testers']['strict'] ?? null);
    }
}
