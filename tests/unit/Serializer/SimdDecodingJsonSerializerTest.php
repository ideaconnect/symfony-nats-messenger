<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\SimdDecodingJsonSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

class SimdDecodingJsonSerializerTest extends TestCase
{
    private SimdDecodingJsonSerializer $serializer;

    protected function setUp(): void
    {
        if (!function_exists('simdjson_decode')) {
            $this->markTestSkipped('The simdjson extension is not available.');
        }

        $this->serializer = new SimdDecodingJsonSerializer();
    }

    /**
     * @test
     */
    public function constructor_WithoutSimdjsonExtension_ThrowsRuntimeException(): void
    {
        // This test needs to run in a separate process since we can't unload extensions
        if (function_exists('simdjson_decode')) {
            $this->markTestSkipped('Cannot test missing extension when extension is loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The simdjson extension is not installed.');

        new SimdDecodingJsonSerializer();
    }

    /**
     * @test
     */
    public function constructor_WithSimdjsonExtension_CreatesInstance(): void
    {
        $serializer = new SimdDecodingJsonSerializer();

        $this->assertInstanceOf(SimdDecodingJsonSerializer::class, $serializer);
        $this->assertInstanceOf(\IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer::class, $serializer);
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class, $serializer);
    }

    /**
     * @test
     */
    public function serialize_WithValidEnvelope_ReturnsJsonString(): void
    {
        $message = new \stdClass();
        $message->data = 'test data';
        $message->number = 42;
        $envelope = new Envelope($message);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $envelope);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Verify it's valid JSON
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
    }

    /**
     * @test
     */
    public function serialize_WithEnvelopeContainingStamps_SerializesSuccessfully(): void
    {
        $message = new \stdClass();
        $message->content = 'test content';
        $stamp = new BusNameStamp('test-bus');
        $envelope = new Envelope($message, [$stamp]);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $envelope);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Verify it's valid JSON
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
    }

    /**
     * @test
     */
    public function serialize_WithComplexMessage_HandlesNestedData(): void
    {
        $complexMessage = new \stdClass();
        $complexMessage->string = 'test string';
        $complexMessage->number = 12345;
        $complexMessage->array = ['a', 'b', 'c'];
        $complexMessage->nested = new \stdClass();
        $complexMessage->nested->value = 'nested value';

        $envelope = new Envelope($complexMessage);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $envelope);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Verify it's valid JSON
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
    }

    /**
     * @test
     */
    public function deserialize_WithValidJsonString_ReturnsDecodedData(): void
    {
        $originalData = [
            'key' => 'value',
            'number' => 42,
            'array' => [1, 2, 3],
            'nested' => ['inner' => 'value']
        ];
        $jsonString = json_encode($originalData);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $jsonString);

        // simdjson_decode may return an object or array depending on implementation
        $this->assertTrue(is_array($result) || is_object($result));
        $this->assertNotNull($result);
    }

    /**
     * @test
     */
    public function deserialize_WithValidJsonObject_ReturnsDecodedData(): void
    {
        $jsonString = '{"name":"John","age":30,"city":"New York"}';

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $jsonString);

        // simdjson_decode may return an object or array depending on implementation
        $this->assertTrue(is_array($result) || is_object($result));
        $this->assertNotNull($result);

        // Check the data is accessible regardless of format
        if (is_array($result)) {
            $this->assertEquals('John', $result['name']);
            $this->assertEquals(30, $result['age']);
        } else {
            $this->assertEquals('John', $result->name);
            $this->assertEquals(30, $result->age);
        }
    }

    /**
     * @test
     */
    public function deserialize_WithValidJsonArray_ReturnsArray(): void
    {
        $jsonString = '[1, 2, 3, "four", true]';

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $jsonString);

        $expected = [1, 2, 3, 'four', true];
        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function deserialize_WithInvalidJson_ThrowsOrReturnsNull(): void
    {
        $invalidJson = 'invalid json string';

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        // simdjson_decode typically throws an exception for invalid JSON
        try {
            $result = $method->invoke($this->serializer, $invalidJson);
            // If no exception is thrown, the result should be null or false
            $this->assertFalse($result !== null && $result !== false);
        } catch (\Exception $e) {
            // Exception is expected for invalid JSON
            $this->assertTrue(true);
        }
    }

    /**
     * @test
     */
    public function deserialize_WithEmptyString_HandlesGracefully(): void
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        try {
            $result = $method->invoke($this->serializer, '');
            // If no exception is thrown, result should be null or false
            $this->assertFalse($result !== null && $result !== false);
        } catch (\Exception $e) {
            // Exception is acceptable for empty string
            $this->assertTrue(true);
        }
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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
    public function decode_WithInvalidSerializedData_ThrowsRuntimeException(): void
    {
        $encodedEnvelope = ['body' => 'invalid serialized data'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        // Suppress igbinary warning
        @$this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function decode_WithNonEnvelopeObject_ThrowsRuntimeException(): void
    {
        $nonEnvelopeObject = new \stdClass();
        $nonEnvelopeObject->data = 'not an envelope';
        $serialized = \igbinary_serialize($nonEnvelopeObject);
        $encodedEnvelope = ['body' => $serialized];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid envelope');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * @test
     */
    public function roundTripSerialization_WithComplexEnvelope_PreservesEnvelopeParts(): void
    {
        $complexMessage = new \stdClass();
        $complexMessage->string = 'test string';
        $complexMessage->number = 12345;
        $complexMessage->array = ['a', 'b', 'c'];

        $stamp1 = new BusNameStamp('main-bus');
        $envelope = new Envelope($complexMessage, [$stamp1]);

        // Encode (uses igbinary internally for envelope serialization)
        $encoded = $this->serializer->encode($envelope);

        // Decode (uses igbinary internally for envelope deserialization)
        $decoded = $this->serializer->decode($encoded);

        $this->assertInstanceOf(Envelope::class, $decoded);
        $decodedMessage = $decoded->getMessage();

        $this->assertEquals($complexMessage->string, $decodedMessage->string);
        $this->assertEquals($complexMessage->number, $decodedMessage->number);
        $this->assertEquals($complexMessage->array, $decodedMessage->array);

        $decodedStamp = $decoded->last(BusNameStamp::class);
        $this->assertInstanceOf(BusNameStamp::class, $decodedStamp);
        $this->assertEquals('main-bus', $decodedStamp->getBusName());
    }

    /**
     * @test
     */
    public function serialize_WithEmptyMessage_HandlesGracefully(): void
    {
        $message = new \stdClass();
        $envelope = new Envelope($message);

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('serialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, $envelope);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Verify it's valid JSON
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
    }

    /**
     * @test
     */
    public function deserialize_WithJsonNull_ReturnsNull(): void
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, 'null');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function deserialize_WithJsonBoolean_ReturnsBoolean(): void
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, 'true');
        $this->assertTrue($result);

        $result = $method->invoke($this->serializer, 'false');
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function deserialize_WithJsonNumber_ReturnsNumber(): void
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, '42');
        $this->assertEquals(42, $result);

        $result = $method->invoke($this->serializer, '3.14');
        $this->assertEquals(3.14, $result);
    }

    /**
     * @test
     */
    public function deserialize_WithJsonString_ReturnsString(): void
    {
        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->serializer);
        $method = $reflection->getMethod('deserialize');
        $method->setAccessible(true);

        $result = $method->invoke($this->serializer, '"hello world"');

        $this->assertEquals('hello world', $result);
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
    public function extendsAbstractEnveloperSerializer(): void
    {
        $this->assertInstanceOf(\IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer::class, $this->serializer);
    }
}