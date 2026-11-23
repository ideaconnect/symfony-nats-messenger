<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Stamp;

use IDCT\NatsMessenger\Stamp\UnrecoverableFailureStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class UnrecoverableFailureStampTest extends TestCase
{
    public function testKeepsWhetherEveryHandlerFailedForGood(): void
    {
        self::assertTrue((new UnrecoverableFailureStamp(true))->unrecoverable);
        self::assertFalse((new UnrecoverableFailureStamp(false))->unrecoverable);
    }

    /**
     * It describes one delivery: a copy Symfony sends runs the handlers again, so the serializers strip it.
     */
    public function testIsNotSentWithTheMessage(): void
    {
        self::assertInstanceOf(NonSendableStampInterface::class, new UnrecoverableFailureStamp(true));
    }
}
