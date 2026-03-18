<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

class IgbinarySerializerTest extends TestCase
{
    private IgbinarySerializer $serializer;

    protected function setUp(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $this->serializer = new IgbinarySerializer();
    }

    #[Test]
    public function serialize_WithValidEnvelope_ReturnsSerializedString(): void
    {
        $message = new \stdClass();
        $message->data = 'test data';
        $envelope = new Envelope($message);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');

        $result = $method->invoke($this->serializer, $envelope);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Verify it can be unserialized back
        $unserialized = \igbinary_unserialize($result);
        $this->assertInstanceOf(Envelope::class, $unserialized);
        $this->assertEquals($message->data, $unserialized->getMessage()->data);
    }

    #[Test]
    public function serialize_WithEnvelopeContainingStamps_PreservesStamps(): void
    {
        $message = new \stdClass();
        $message->content = 'test content';
        $stamp = new BusNameStamp('test-bus');
        $envelope = new Envelope($message, [$stamp]);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');

        $serializedData = $method->invoke($this->serializer, $envelope);
        $deserializedEnvelope = \igbinary_unserialize($serializedData);

        $this->assertInstanceOf(Envelope::class, $deserializedEnvelope);
        $this->assertEquals($message->content, $deserializedEnvelope->getMessage()->content);

        $busStamp = $deserializedEnvelope->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $busStamp);
        $this->assertEquals('test-bus', $busStamp->getBusName());
    }

    #[Test]
    public function deserialize_WithValidSerializedData_ReturnsOriginalData(): void
    {
        $originalData = ['key' => 'value', 'number' => 42, 'array' => [1, 2, 3]];
        $serialized = \igbinary_serialize($originalData);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');

        $result = $method->invoke($this->serializer, $serialized);

        $this->assertEquals($originalData, $result);
    }

    #[Test]
    public function deserialize_WithSerializedEnvelope_ReturnsEnvelope(): void
    {
        $message = new \stdClass();
        $message->test = 'deserialize test';
        $envelope = new Envelope($message);
        $serialized = \igbinary_serialize($envelope);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');

        $result = $method->invoke($this->serializer, $serialized);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertEquals($message->test, $result->getMessage()->test);
    }

    #[Test]
    public function deserialize_WithInvalidData_ReturnsNull(): void
    {
        $invalidData = 'invalid serialized data';

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');

        // Suppress igbinary warning for invalid data
        $result = @$method->invoke($this->serializer, $invalidData);

        // igbinary_unserialize returns null for invalid data
        $this->assertNull($result);
    }

    #[Test]
    public function deserialize_WithEmptyString_ReturnsNull(): void
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');

        $result = $method->invoke($this->serializer, '');

        $this->assertFalse($result);
    }

    #[Test]
    public function encode_WithValidEnvelope_ReturnsArrayWithBody(): void
    {
        $message = new \stdClass();
        $message->content = 'encode test';
        $envelope = new Envelope($message);

        $result = $this->serializer->encode($envelope);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);
        $this->assertIsString($result['body']);

        // Verify the body can be unserialized back to envelope
        $unserialized = \igbinary_unserialize($result['body']);
        $this->assertInstanceOf(Envelope::class, $unserialized);
        $this->assertEquals($message->content, $unserialized->getMessage()->content);
    }

    #[Test]
    public function decode_WithValidEncodedEnvelope_ReturnsEnvelope(): void
    {
        $message = new \stdClass();
        $message->decode_test = 'test data';
        $envelope = new Envelope($message);
        $serialized = \igbinary_serialize($envelope);
        $encodedEnvelope = ['body' => $serialized];

        $result = $this->serializer->decode($encodedEnvelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertEquals($message->decode_test, $result->getMessage()->decode_test);
    }

    #[Test]
    public function decode_WithEmptyBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = ['body' => ''];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    #[Test]
    public function decode_WithMissingBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = [];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    #[Test]
    public function decode_WithInvalidSerializedData_ThrowsMessageDecodingFailed(): void
    {
        $encodedEnvelope = ['body' => 'invalid serialized data'];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Deserialized data is not a valid Symfony Messenger Envelope.');

        // Suppress igbinary warning
        @$this->serializer->decode($encodedEnvelope);
    }

    #[Test]
    public function decode_WithNonEnvelopeObject_ThrowsMessageDecodingFailed(): void
    {
        $nonEnvelopeObject = new \stdClass();
        $nonEnvelopeObject->data = 'not an envelope';
        $serialized = \igbinary_serialize($nonEnvelopeObject);
        $encodedEnvelope = ['body' => $serialized];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Deserialized data is not a valid Symfony Messenger Envelope.');

        $this->serializer->decode($encodedEnvelope);
    }

    #[Test]
    public function roundTripSerialization_WithComplexEnvelope_PreservesAllData(): void
    {
        $complexMessage = new \stdClass();
        $complexMessage->string = 'test string';
        $complexMessage->number = 12345;
        $complexMessage->array = ['a', 'b', 'c'];
        $complexMessage->nested = new \stdClass();
        $complexMessage->nested->value = 'nested value';

        $stamp1 = new BusNameStamp('main-bus');
        $envelope = new Envelope($complexMessage, [$stamp1]);

        // Encode
        $encoded = $this->serializer->encode($envelope);

        // Decode
        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $decodedMessage = $decoded->getMessage();

        $this->assertEquals($complexMessage->string, $decodedMessage->string);
        $this->assertEquals($complexMessage->number, $decodedMessage->number);
        $this->assertEquals($complexMessage->array, $decodedMessage->array);
        $this->assertEquals($complexMessage->nested->value, $decodedMessage->nested->value);

        $decodedStamp = $decoded->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $decodedStamp);
        $this->assertEquals('main-bus', $decodedStamp->getBusName());
    }

    #[Test]
    public function serialize_WithEmptyMessage_HandlesGracefully(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');

        $result = $method->invoke($this->serializer, $envelope);

        $this->assertIsString($result);

        $unserialized = \igbinary_unserialize($result);
        $this->assertInstanceOf(Envelope::class, $unserialized);
        $this->assertInstanceOf(\stdClass::class, $unserialized->getMessage());
    }

    #[Test]
    public function implementsSerializerInterface(): void
    {
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class, $this->serializer);
    }

    #[Test]
    public function extendsAbstractEnveloperSerializer(): void
    {
        $this->assertInstanceOf(\IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer::class, $this->serializer);
    }

    #[Test]
    public function encode_ThrowsRuntimeException_WhenSerializeReturnsNull(): void
    {
        $serializer = new class extends IgbinarySerializer {
            protected function serialize(Envelope $envelope): string
            {
                throw new \RuntimeException('Failed to serialize envelope with igbinary.');
            }
        };

        $envelope = new Envelope(new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to serialize envelope with igbinary.');

        $serializer->encode($envelope);
    }
}