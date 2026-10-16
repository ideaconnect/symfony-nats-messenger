<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * How the unit suite is configured and how CI runs it. PHPUnit reports a deprecated setting or API, and from
 * PHPUnit 12 on a test double that sets no expectations, as an issue that leaves the exit code at 0, so such
 * issues pile up unnoticed. The suite is free of both on PHPUnit 13, and these settings keep it that way.
 */
final class UnitSuiteConfigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testAPhpunitDeprecationFailsTheUnitSuite(): void
    {
        $config = simplexml_load_file(self::ROOT . '/phpunit.xml.dist');
        self::assertNotFalse($config);

        self::assertSame('true', (string) $config['failOnPhpunitDeprecation']);
    }

    /**
     * failOnPhpunitNotice cannot go in phpunit.xml.dist: PHPUnit 11, which PHP 8.2 installs, does not know the
     * attribute, fails the validation of the file and exits 1. CI passes the option on the command line to the
     * job that installs PHPUnit 13.
     */
    public function testAPhpunitNoticeFailsTheUnitSuiteOnTheJobThatRunsPhpunit13(): void
    {
        $workflow = (string) file_get_contents(self::ROOT . '/.github/workflows/ci.yml');

        self::assertMatchesRegularExpression(
            "/- php-version: '8\\.5'\\n(?:[ ]+(?:#.*|client: \\w+)\\n)*[ ]+phpunit-args: --fail-on-phpunit-notice\\n/",
            $workflow,
        );
        self::assertStringContainsString('run: composer test:unit -- ${{ matrix.phpunit-args }}', $workflow);
    }
}
