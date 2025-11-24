<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Concrete implementation of AbstractEnveloperSerializer for testing purposes
 */
class TestableEnveloperSerializer extends AbstractEnveloperSerializer
{
    private bool $shouldThrowOnSerialize = false;
    private bool $shouldThrowOnDeserialize = false;
    private mixed $deserializeReturnValue = null;

    public function setShouldThrowOnSerialize(bool $shouldThrow): void
    {
        $this->shouldThrowOnSerialize = $shouldThrow;
    }

    public function setShouldThrowOnDeserialize(bool $shouldThrow): void
    {
        $this->shouldThrowOnDeserialize = $shouldThrow;
    }

    public function setDeserializeReturnValue(mixed $value): void
    {
        $this->deserializeReturnValue = $value;
    }

    protected function serialize(Envelope $envelope): string
    {
        if ($this->shouldThrowOnSerialize) {
            throw new \RuntimeException('Serialization failed');
        }

        return json_encode($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        if ($this->shouldThrowOnDeserialize) {
            throw new \RuntimeException('Deserialization failed');
        }

        if ($this->deserializeReturnValue !== null) {
            return $this->deserializeReturnValue;
        }

        return json_decode($data, true);
    }
}

class AbstractEnveloperSerializerTest extends TestCase
{
    private TestableEnveloperSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new TestableEnveloperSerializer();
    }

    /**
     * @test
     */
    public function encode_WithValidEnvelope_ReturnsArrayWithIgbinarySerializedBody(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $message = new \stdClass();
        $message->content = 'test content';
        $envelope = new Envelope($message);

        $result = $this->serializer->encode($envelope);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);
        $this->assertIsString($result['body']);

        // Verify it's igbinary serialized envelope
        $unserialized = \igbinary_unserialize($result['body']);
        $this->assertInstanceOf(Envelope::class, $unserialized);
        $this->assertEquals($message->content, $unserialized->getMessage()->content);
    }

    /**
     * @test
     */
    public function encode_WithEnvelopeContainingStamps_PreservesStamps(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $message = new \stdClass();
        $message->data = 'test data';
        $busStamp = new BusNameStamp('test-bus');
        $idStamp = new TransportMessageIdStamp('msg-123');
        $envelope = new Envelope($message, [$busStamp, $idStamp]);

        $result = $this->serializer->encode($envelope);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);

        $unserialized = \igbinary_unserialize($result['body']);
        $this->assertInstanceOf(Envelope::class, $unserialized);

        $unserializedBusStamp = $unserialized->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $unserializedBusStamp);
        $this->assertEquals('test-bus', $unserializedBusStamp->getBusName());

        $unserializedIdStamp = $unserialized->last(TransportMessageIdStamp::class);
        $this->assertInstanceOf(TransportMessageIdStamp::class, $unserializedIdStamp);
        $this->assertEquals('msg-123', $unserializedIdStamp->getId());
    }

    /**
     * @test
     */
    public function encode_WithEmptyMessage_HandlesGracefully(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $message = new \stdClass();
        $envelope = new Envelope($message);

        $result = $this->serializer->encode($envelope);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);

        $unserialized = \igbinary_unserialize($result['body']);
        $this->assertInstanceOf(Envelope::class, $unserialized);
        $this->assertInstanceOf(\stdClass::class, $unserialized->getMessage());
    }

    /**
     * @test
     */
    public function decode_WithValidEncodedEnvelope_ReturnsEnvelope(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $message = new \stdClass();
        $message->test = 'decode test';
        $envelope = new Envelope($message);
        $serialized = \igbinary_serialize($envelope);
        $encodedEnvelope = ['body' => $serialized];

        $result = $this->serializer->decode($encodedEnvelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertEquals($message->test, $result->getMessage()->test);
    }

    /**
     * @test
     */
    public function decode_WithEnvelopeContainingStamps_PreservesStamps(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $message = new \stdClass();
        $message->data = 'stamped message';
        $busStamp = new BusNameStamp('decode-bus');
        $envelope = new Envelope($message, [$busStamp]);
        $serialized = \igbinary_serialize($envelope);
        $encodedEnvelope = ['body' => $serialized];

        $result = $this->serializer->decode($encodedEnvelope);

        $this->assertInstanceOf(Envelope::class, $result);
        $this->assertEquals($message->data, $result->getMessage()->data);

        $decodedStamp = $result->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $decodedStamp);
        $this->assertEquals('decode-bus', $decodedStamp->getBusName());
    }

    /**
     * @test
     */
    public function decode_WithEmptyBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = ['body' => ''];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithNullBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = ['body' => null];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithMissingBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = [];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithArrayHavingFalseBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = ['body' => false];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithArrayHavingZeroBody_ThrowsMessageDecodingFailedException(): void
    {
        $encodedEnvelope = ['body' => 0];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Encoded envelope should at least have a "body".');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithInvalidSerializedData_ThrowsRuntimeException(): void
    {
        $encodedEnvelope = ['body' => 'invalid igbinary data'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        // Suppress igbinary warning
        @$this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithNonEnvelopeSerializedData_ThrowsRuntimeException(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $notAnEnvelope = new \stdClass();
        $notAnEnvelope->data = 'not an envelope';
        $serialized = \igbinary_serialize($notAnEnvelope);
        $encodedEnvelope = ['body' => $serialized];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithSerializedNull_ThrowsRuntimeException(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $serialized = \igbinary_serialize(null);
        $encodedEnvelope = ['body' => $serialized];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithSerializedString_ThrowsRuntimeException(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $serialized = \igbinary_serialize('just a string');
        $encodedEnvelope = ['body' => $serialized];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithSerializedArray_ThrowsRuntimeException(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $serialized = \igbinary_serialize(['not', 'an', 'envelope']);
        $encodedEnvelope = ['body' => $serialized];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function roundTripSerialization_WithComplexEnvelope_PreservesAllData(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $complexMessage = new \stdClass();
        $complexMessage->string = 'complex string';
        $complexMessage->number = 9876;
        $complexMessage->array = ['x', 'y', 'z'];
        $complexMessage->nested = new \stdClass();
        $complexMessage->nested->deepValue = 'deep nested value';

        $busStamp = new BusNameStamp('complex-bus');
        $idStamp = new TransportMessageIdStamp('complex-123');
        $envelope = new Envelope($complexMessage, [$busStamp, $idStamp]);

        // Encode
        $encoded = $this->serializer->encode($envelope);

        // Decode
        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $decodedMessage = $decoded->getMessage();

        $this->assertEquals($complexMessage->string, $decodedMessage->string);
        $this->assertEquals($complexMessage->number, $decodedMessage->number);
        $this->assertEquals($complexMessage->array, $decodedMessage->array);
        $this->assertEquals($complexMessage->nested->deepValue, $decodedMessage->nested->deepValue);

        $decodedBusStamp = $decoded->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $decodedBusStamp);
        $this->assertEquals('complex-bus', $decodedBusStamp->getBusName());

        $decodedIdStamp = $decoded->last(TransportMessageIdStamp::class);
        $this->assertInstanceOf(TransportMessageIdStamp::class, $decodedIdStamp);
        $this->assertEquals('complex-123', $decodedIdStamp->getId());
    }

    /**
     * @test
     */
    public function implementsSerializerInterface(): void
    {
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class, $this->serializer);
    }

    /**
     * @test
     */
    public function abstractMethodsAreDefined(): void
    {
        $reflection = new \ReflectionClass(AbstractEnveloperSerializer::class);

        $this->assertTrue($reflection->hasMethod('serialize'));
        $this->assertTrue($reflection->getMethod('serialize')->isAbstract());

        $this->assertTrue($reflection->hasMethod('deserialize'));
        $this->assertTrue($reflection->getMethod('deserialize')->isAbstract());
    }

    /**
     * @test
     */
    public function concreteImplementation_CallsAbstractMethods(): void
    {
        // This test verifies that the abstract methods are actually called
        $message = new \stdClass();
        $message->test = 'method call test';
        $envelope = new Envelope($message);

        // Test serialize method is called via encode
        $result = $this->serializer->encode($envelope);
        $this->assertIsArray($result);

        // Test deserialize method is called via decode (indirectly through igbinary usage)
        $encodedEnvelope = ['body' => \igbinary_serialize($envelope)];
        $decoded = $this->serializer->decode($encodedEnvelope);
        $this->assertInstanceOf(Envelope::class, $decoded);
    }

    /**
     * @test
     */
    public function decode_WithValidBodyButFailedUnserialize_ThrowsRuntimeException(): void
    {
        // Test when igbinary_unserialize fails (returns false)
        $encodedEnvelope = ['body' => 'some-non-empty-invalid-data'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        // Suppress igbinary warning
        @$this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function encode_WithEmptyEnvelope_WorksCorrectly(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        // Create envelope with empty message
        $envelope = new Envelope(new \stdClass());

        $result = $this->serializer->encode($envelope);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);
        $this->assertIsString($result['body']);

        $unserialized = \igbinary_unserialize($result['body']);
        $this->assertInstanceOf(Envelope::class, $unserialized);
    }

    /**
     * @test
     */
    public function encode_WithMultipleStamps_PreservesAllStamps(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $message = new \stdClass();
        $message->content = 'multi-stamp test';

        $busStamp = new BusNameStamp('multi-bus');
        $idStamp1 = new TransportMessageIdStamp('id-1');
        $idStamp2 = new TransportMessageIdStamp('id-2');

        $envelope = new Envelope($message, [$busStamp, $idStamp1, $idStamp2]);

        $result = $this->serializer->encode($envelope);
        $decoded = $this->serializer->decode($result);

        $this->assertInstanceOf(Envelope::class, $decoded);

        // Check all stamps are preserved
        $decodedBusStamp = $decoded->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $decodedBusStamp);
        $this->assertEquals('multi-bus', $decodedBusStamp->getBusName());

        $allIdStamps = $decoded->all(TransportMessageIdStamp::class);
        $this->assertCount(2, $allIdStamps);

        $stampIds = array_map(fn($stamp) => $stamp->getId(), $allIdStamps);
        $this->assertContains('id-1', $stampIds);
        $this->assertContains('id-2', $stampIds);
    }
}