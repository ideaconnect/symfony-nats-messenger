<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

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
 * This class must compile and work correctly — if the AbstractEnveloperSerializer API
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
}
