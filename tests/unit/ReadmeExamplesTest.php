<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards every fenced ```php example in README.md against syntax rot.
 *
 * Each block is linted with `php -l` so a snippet that stops being valid PHP (a renamed class, a
 * dropped semicolon, an attribute typo) fails CI. The semantic examples - DSN strings, YAML option
 * blocks and the custom-serializer class - are additionally exercised for behavior by
 * {@see \IDCT\NatsMessenger\Tests\Unit\Options\NatsTransportConfigurationBuilderTest} (Readme* methods)
 * and {@see \IDCT\NatsMessenger\Tests\Unit\Serializer\AbstractEnveloperSerializerTest}.
 */
final class ReadmeExamplesTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';

    #[DataProvider('readmePhpBlocksProvider')]
    public function testReadmePhpExampleIsSyntacticallyValid(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'readme_php_');
        if ($file === false) {
            self::fail('Could not create a temp file for linting.');
        }

        try {
            file_put_contents($file, "<?php\n" . $code);
            $output = [];
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        } finally {
            unlink($file);
        }

        self::assertSame(0, $exit, "A README PHP example is not valid PHP:\n" . implode("\n", $output));
    }

    public function testReadmeContainsThePhpExamplesWeExpect(): void
    {
        // Pin the count so a future edit that drops (or stops fencing) an example is noticed.
        self::assertCount(5, iterator_to_array(self::readmePhpBlocksProvider()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readmePhpBlocksProvider(): iterable
    {
        $readme = (string) file_get_contents(self::README);
        preg_match_all('/```php\n(.*?)```/s', $readme, $matches);

        foreach ($matches[1] as $index => $code) {
            yield 'README php block #' . ($index + 1) => [$code];
        }
    }
}
