<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Stamp;

use IDCT\NatsMessenger\Stamp\ReceivedInNatsModeStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class ReceivedInNatsModeStampTest extends TestCase
{
    public function testKeepsTheDeliveryToken(): void
    {
        self::assertSame('$JS.ACK.test-stream.client.1.1.1.0.0', (new ReceivedInNatsModeStamp('$JS.ACK.test-stream.client.1.1.1.0.0'))->deliveryToken);
    }

    /**
     * It describes one delivery, so the serializers strip it.
     */
    public function testIsNotSentWithTheMessage(): void
    {
        self::assertInstanceOf(NonSendableStampInterface::class, new ReceivedInNatsModeStamp('$JS.ACK.test-stream.client.1.1.1.0.0'));
    }
}
