<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Stamp;

use IDCT\NatsMessenger\Stamp\NatsReceivedSubjectStamp;
use IDCT\NatsMessenger\Stamp\NatsSubjectStamp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class NatsSubjectStampTest extends TestCase
{
    public function testKeepsTheSubject(): void
    {
        self::assertSame('orders.update.42', (new NatsSubjectStamp('orders.update.42'))->subject);
    }

    #[DataProvider('publishableSubjectsProvider')]
    public function testAcceptsASubjectAMessageCanBePublishedTo(string $subject): void
    {
        self::assertSame($subject, (new NatsSubjectStamp($subject))->subject);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishableSubjectsProvider(): iterable
    {
        yield 'one token' => ['orders'];
        yield 'several tokens' => ['orders.updated.42'];
        yield 'a one-character token' => ['a.b.c'];
        yield 'hyphens, underscores and other non-whitespace characters' => ['orders.updated.ab-cd_42:7'];
    }

    /**
     * The subject goes into the PUB frame as it is: whitespace breaks the frame, an empty token or a wildcard token
     * is refused by the server. Each fails here, at dispatch, instead of as a connection error later.
     */
    #[DataProvider('unpublishableSubjectsProvider')]
    public function testRefusesASubjectAMessageCannotBePublishedTo(string $subject): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NatsSubjectStamp($subject);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpublishableSubjectsProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'only whitespace' => [' '];
        yield 'a space inside' => ['orders updated.42'];
        yield 'a line break inside' => ["orders.updated\n.42"];
        yield 'a carriage return at the end' => ["orders.updated.42\r"];
        yield 'a tab inside' => ["orders\t.updated"];
        yield 'an empty token' => ['orders..42'];
        yield 'a leading dot' => ['.orders'];
        yield 'a trailing dot' => ['orders.updated.'];
        yield 'a * token' => ['orders.*.42'];
        yield 'a > token' => ['orders.>'];
        yield 'only a wildcard' => ['>'];
    }

    public function testNamesTheSubjectItRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"orders.updated."');

        new NatsSubjectStamp('orders.updated.');
    }

    /**
     * Serializers strip non-sendable stamps, so neither travels in the payload.
     */
    public function testNeitherStampIsSentWithTheMessage(): void
    {
        self::assertInstanceOf(NonSendableStampInterface::class, new NatsSubjectStamp('orders'));
        self::assertInstanceOf(NonSendableStampInterface::class, new NatsReceivedSubjectStamp('orders'));
    }

    public function testReceivedStampKeepsTheSubject(): void
    {
        self::assertSame('orders.update.42', (new NatsReceivedSubjectStamp('orders.update.42'))->subject);
    }
}
