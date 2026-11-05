<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NATS\Auth\NkeySeedSigner;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The keys the unit tests build must be real NKeys: a mistake in the helper would otherwise show up as a test of the
 * transport passing or failing for the wrong reason.
 */
final class TestNkeysTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function keyTypes(): iterable
    {
        yield 'user' => [TestNkeys::USER, 'SU', 'U'];
        yield 'account' => [TestNkeys::ACCOUNT, 'SA', 'A'];
        yield 'operator' => [TestNkeys::OPERATOR, 'SO', 'O'];
        yield 'server' => [TestNkeys::SERVER, 'SN', 'N'];
        yield 'cluster' => [TestNkeys::CLUSTER, 'SC', 'C'];
        yield 'curve' => [TestNkeys::CURVE, 'SX', 'X'];
    }

    #[RequiresPhpExtension('sodium')]
    #[DataProvider('keyTypes')]
    public function testTheClientReadsTheKeysAndDerivesTheSamePublicKey(int $type, string $seedPrefix, string $publicPrefix): void
    {
        $seed = TestNkeys::seed('some-key', $type);

        self::assertStringStartsWith($seedPrefix, $seed);
        self::assertSame(58, strlen($seed));
        self::assertStringStartsWith($publicPrefix, TestNkeys::publicKey('some-key', $type));
        self::assertSame(56, strlen(TestNkeys::publicKey('some-key', $type)));
        self::assertSame(TestNkeys::publicKey('some-key', $type), (new NkeySeedSigner($seed))->publicKey());
    }

    #[RequiresPhpExtension('sodium')]
    public function testANameAlwaysGivesTheSameKeyAndAnotherNameAnother(): void
    {
        self::assertSame(TestNkeys::seed('one'), TestNkeys::seed('one'));
        self::assertNotSame(TestNkeys::seed('one'), TestNkeys::seed('two'));
        self::assertNotSame(TestNkeys::publicKey('one'), TestNkeys::publicKey('two'));
    }
}
