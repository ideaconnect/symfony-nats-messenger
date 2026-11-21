<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Concrete implementation of AbstractEnveloperSerializer for testing purposes.
 */
class TestableEnveloperSerializer extends AbstractEnveloperSerializer
{
    private bool $shouldReturnInvalidEnvelope = false;

    public function setShouldReturnInvalidEnvelope(bool $value): void
    {
        $this->shouldReturnInvalidEnvelope = $value;
    }

    protected function serialize(Envelope $envelope): string
    {
        return serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        if ($this->shouldReturnInvalidEnvelope) {
            return 'not-an-envelope';
        }
        return unserialize($data);
    }
}

/**
 * Mirrors the exact custom serializer example from README.md.
 * This class must compile and work correctly - if the AbstractEnveloperSerializer API
 * changes (method signatures, return types), this test class will break, catching drift.
 */
class ReadmeExampleSerializer extends AbstractEnveloperSerializer
{
    protected function serialize(Envelope $envelope): string
    {
        // Your custom serialization logic
        return serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        // Your custom deserialization logic
        return unserialize($data);
    }
}

class AbstractEnveloperSerializerTest extends TestCase
{
    private TestableEnveloperSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new TestableEnveloperSerializer();
    }

    #[Test]
    public function encode_WithValidEnvelope_ReturnsArrayWithBody(): void
    {
        $message = new \stdClass();
        $message->data = 'test data';
        $envelope = new Envelope($message);

        $result = $this->serializer->encode($envelope);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);
        $this->assertIsString($result['body']);
        $this->assertNotEmpty($result['body']);
    }

    #[Test]
    public function encode_WithEnvelopeContainingStamps_PreservesStampsInBody(): void
    {
        $message = new \stdClass();
        $message->content = 'test content';
        $stamp = new BusNameStamp('test-bus');
        $envelope = new Envelope($message, [$stamp]);

        $encoded = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($encoded);

        $busStamp = $decoded->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $busStamp);
        $this->assertEquals('test-bus', $busStamp->getBusName());
    }

    /**
     * Stamps Symfony marks as non-sendable are left out, as its own serializers leave them out: they describe
     * the envelope's journey through this process, and the AckStamp a worker adds holds a closure that cannot
     * be serialized at all (#46). Sendable stamps travel as before.
     */
    #[Test]
    public function encode_WithNonSendableStamps_LeavesThemOutAndKeepsTheRest(): void
    {
        $envelope = new Envelope(new \stdClass(), [
            new BusNameStamp('bus'),
            new DelayStamp(1000),
            new ReceivedStamp('async'),
            new ConsumedByWorkerStamp(),
            new AckStamp(static function (): void {}),
        ]);

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));

        $this->assertSame('bus', $decoded->last(BusNameStamp::class)?->getBusName());
        $this->assertSame(1000, $decoded->last(DelayStamp::class)?->getDelay());
        $this->assertSame([], $decoded->all(ReceivedStamp::class));
        $this->assertSame([], $decoded->all(ConsumedByWorkerStamp::class));
        $this->assertSame([], $decoded->all(AckStamp::class));
    }

    /**
     * A serializer that adds headers sees the envelope as it is sent, without the non-sendable stamps.
     */
    #[Test]
    public function encode_GivesHeadersTheEnvelopeWithoutNonSendableStamps(): void
    {
        $serializer = new class extends AbstractEnveloperSerializer {
            /** @var list<string> */
            public array $stampsSeenByHeaders = [];

            protected function headers(Envelope $envelope): array
            {
                $this->stampsSeenByHeaders = array_keys($envelope->all());

                return [];
            }

            protected function serialize(Envelope $envelope): string
            {
                return serialize($envelope);
            }

            protected function deserialize(string $data): mixed
            {
                return unserialize($data);
            }
        };

        $serializer->encode(new Envelope(new \stdClass(), [new BusNameStamp('bus'), new ReceivedStamp('async')]));

        $this->assertSame([BusNameStamp::class], $serializer->stampsSeenByHeaders);
    }

    #[Test]
    public function decode_WithValidEncodedEnvelope_ReturnsEnvelope(): void
    {
        $message = new \stdClass();
        $message->data = 'test data';
        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $this->assertEquals($message->data, $decoded->getMessage()->data);
    }

    #[Test]
    public function decode_WithEmptyBody_ThrowsMessageDecodingFailedException(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode(['body' => '']);
    }

    #[Test]
    public function decode_WithMissingBody_ThrowsMessageDecodingFailedException(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode([]);
    }

    #[Test]
    public function decode_WithNullBody_ThrowsMessageDecodingFailedException(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode(['body' => null]);
    }

    #[Test]
    public function decode_WhenDeserializeReturnsNonEnvelope_ThrowsMessageDecodingFailed(): void
    {
        $this->serializer->setShouldReturnInvalidEnvelope(true);

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Deserialized data is not a valid Symfony Messenger Envelope.');

        // Provide a valid body so we pass the empty check but fail on the Envelope check
        $this->serializer->decode(['body' => 'some-data']);
    }

    #[Test]
    public function encode_ThenDecode_ReturnsEquivalentEnvelope(): void
    {
        $message = new \stdClass();
        $message->id = 123;
        $message->name = 'Test Message';
        $message->nested = ['key' => 'value'];
        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $decodedMessage = $decoded->getMessage();
        $this->assertEquals(123, $decodedMessage->id);
        $this->assertEquals('Test Message', $decodedMessage->name);
        $this->assertEquals(['key' => 'value'], $decodedMessage->nested);
    }

    #[Test]
    public function encode_WithMultipleStamps_PreservesAllStamps(): void
    {
        $message = new \stdClass();
        $stamp1 = new BusNameStamp('bus-1');
        $stamp2 = new BusNameStamp('bus-2');
        $envelope = new Envelope($message, [$stamp1, $stamp2]);

        $encoded = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($encoded);

        $stamps = $decoded->all(BusNameStamp::class);
        $this->assertCount(2, $stamps);
    }

    #[Test]
    public function encode_WithValidEnvelope_IncludesHeadersKey(): void
    {
        $message = new \stdClass();
        $message->data = 'test data';
        $envelope = new Envelope($message);

        $result = $this->serializer->encode($envelope);

        $this->assertArrayHasKey('headers', $result);
        $this->assertIsArray($result['headers']);
        $this->assertEmpty($result['headers']);
    }

    #[Test]
    public function readmeCustomSerializerExample_EncodeDecode_RoundTrips(): void
    {
        $serializer = new ReadmeExampleSerializer();

        $message = new \stdClass();
        $message->id = 42;
        $message->text = 'README example';
        $stamp = new BusNameStamp('readme-bus');
        $envelope = new Envelope($message, [$stamp]);

        $encoded = $serializer->encode($envelope);

        $this->assertIsArray($encoded);
        $this->assertArrayHasKey('body', $encoded);
        $this->assertIsString($encoded['body']);
        $this->assertNotEmpty($encoded['body']);
        $this->assertArrayHasKey('headers', $encoded);

        $decoded = $serializer->decode($encoded);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $this->assertEquals(42, $decoded->getMessage()->id);
        $this->assertEquals('README example', $decoded->getMessage()->text);

        $busStamp = $decoded->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $busStamp);
        $this->assertEquals('readme-bus', $busStamp->getBusName());
    }

    #[Test]
    public function readmeCustomSerializerExample_DecodeInvalidBody_ThrowsException(): void
    {
        $serializer = new ReadmeExampleSerializer();

        $this->expectException(MessageDecodingFailedException::class);

        $serializer->decode(['body' => '']);
    }

    /**
     * An exception deserialize() throws is a decoding failure, with it as the previous exception: a transport takes only
     * a MessageDecodingFailedException for a message that cannot be decoded, as Symfony's own transports do.
     */
    #[Test]
    public function decode_WhoseDeserializeThrows_ThrowsMessageDecodingFailedWithTheCause(): void
    {
        $cause = new \UnexpectedValueException('truncated payload');
        $serializer = new class ($cause) extends AbstractEnveloperSerializer {
            public function __construct(private readonly \Throwable $cause)
            {
            }

            protected function serialize(Envelope $envelope): string
            {
                return '';
            }

            protected function deserialize(string $data): mixed
            {
                throw $this->cause;
            }
        };

        try {
            $serializer->decode(['body' => 'x']);
            $this->fail('decode() should have failed');
        } catch (MessageDecodingFailedException $failure) {
            $this->assertSame('Could not decode the envelope: truncated payload', $failure->getMessage());
            $this->assertSame($cause, $failure->getPrevious());
        }
    }

    /**
     * A MessageDecodingFailedException deserialize() throws goes out as it is.
     */
    #[Test]
    public function decode_WhoseDeserializeThrowsADecodingFailure_ThrowsItAsItIs(): void
    {
        $cause = new MessageDecodingFailedException('a field is missing');
        $serializer = new class ($cause) extends AbstractEnveloperSerializer {
            public function __construct(private readonly \Throwable $cause)
            {
            }

            protected function serialize(Envelope $envelope): string
            {
                return '';
            }

            protected function deserialize(string $data): mixed
            {
                throw $this->cause;
            }
        };

        try {
            $serializer->decode(['body' => 'x']);
            $this->fail('decode() should have failed');
        } catch (MessageDecodingFailedException $failure) {
            $this->assertSame($cause, $failure);
        }
    }

    /**
     * A deprecation deserialize() raises goes to the error handler there was, which is restored afterwards, and the
     * envelope is decoded.
     */
    #[Test]
    public function decode_WhoseDeserializeRaisesADeprecation_PassesItOnAndDecodes(): void
    {
        $envelope = new Envelope(new \stdClass());
        $serializer = new class ($envelope) extends AbstractEnveloperSerializer {
            public function __construct(private readonly Envelope $envelope)
            {
            }

            protected function serialize(Envelope $envelope): string
            {
                return '';
            }

            protected function deserialize(string $data): mixed
            {
                trigger_error('an old format', E_USER_DEPRECATED);

                return $this->envelope;
            }
        };
        $seen = [];
        $handler = static function (int $type, string $message) use (&$seen): bool {
            $seen[] = [$type, $message];

            return true;
        };
        set_error_handler($handler);

        try {
            $decoded = $serializer->decode(['body' => 'x']);
            // The error handler there was is in place again.
            trigger_error('after decode()', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
        }

        $this->assertSame($envelope, $decoded);
        $this->assertSame([[E_USER_DEPRECATED, 'an old format'], [E_USER_DEPRECATED, 'after decode()']], $seen);
    }

    /**
     * A notice deserialize() raises is a decoding failure, as a warning is.
     */
    #[Test]
    public function decode_WhoseDeserializeRaisesANotice_ThrowsMessageDecodingFailed(): void
    {
        $serializer = new class extends AbstractEnveloperSerializer {
            protected function serialize(Envelope $envelope): string
            {
                return '';
            }

            protected function deserialize(string $data): mixed
            {
                trigger_error('offset 3 of 9', E_USER_NOTICE);

                return null;
            }
        };

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Could not decode the envelope: offset 3 of 9');

        $serializer->decode(['body' => 'x']);
    }
}
