<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use IDCT\NatsMessenger\Tests\Support\AutoloadedInvoice;
use IDCT\NatsMessenger\Tests\Support\DecodesOnWakeup;
use IDCT\NatsMessenger\Tests\Support\NoticeOnUnserialize;
use IDCT\NatsMessenger\Tests\Support\NoticeOnWakeup;
use IDCT\NatsMessenger\Tests\Support\OwnCacheOnWakeup;
use IDCT\NatsMessenger\Tests\Support\RecordedErrors;
use IDCT\NatsMessenger\Tests\Support\SuppressedWarningOnWakeup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

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
    public function deserialize_WithEmptyString_ReturnsFalse(): void
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

    /**
     * A worker adds an AckStamp, which holds a closure, to every message it handles, and Symfony's retry and
     * failure transport re-send that envelope. igbinary cannot serialize a closure, so the re-send threw
     * "Serialization of 'Closure' is not allowed" and stopped the worker; JetStream then redelivered the
     * message into the same failure (#46).
     */
    #[Test]
    public function encode_EnvelopeAWorkerIsHandling_EncodesWithoutItsNonSendableStamps(): void
    {
        $message = new \stdClass();
        $message->content = 'retry me';
        $envelope = new Envelope($message, [new ReceivedStamp('async'), new AckStamp(static function (): void {})]);

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));

        $this->assertSame('retry me', $decoded->getMessage()->content);
        $this->assertSame([], $decoded->all(AckStamp::class));
        $this->assertSame([], $decoded->all(ReceivedStamp::class));
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

    /**
     * A body that is not igbinary makes igbinary warn: the warning is the decoding failure, and is not raised.
     */
    #[Test]
    public function decode_WithInvalidSerializedData_ThrowsMessageDecodingFailed(): void
    {
        $encodedEnvelope = ['body' => 'invalid serialized data'];

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Could not decode the envelope: igbinary_unserialize_header: unsupported version');

        $this->serializer->decode($encodedEnvelope);
    }

    /**
     * Symfony's error handler throws a warning as an ErrorException in debug mode, which a transport would not take for
     * a decode failure, and would leave the message to come back for ever: the warning is a MessageDecodingFailedException
     * whatever handles errors, as with Symfony's PhpSerializer.
     */
    #[Test]
    public function decode_WithInvalidSerializedDataUnderAnErrorHandlerThatThrows_ThrowsMessageDecodingFailed(): void
    {
        set_error_handler(static function (int $type, string $message): never {
            throw new \ErrorException($message, 0, $type);
        });

        try {
            $this->serializer->decode(['body' => 'invalid serialized data']);
            $this->fail('decode() should have failed');
        } catch (MessageDecodingFailedException $failure) {
            $this->assertStringStartsWith('Could not decode the envelope: igbinary_unserialize_header', $failure->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A message whose __wakeup() or __unserialize() raises a warning or a notice, one it suppresses too, decodes: the
     * error is the application's, and goes to the error handler there was, as up to 5.9.x. 5.10.0 made every such
     * message one that cannot be decoded, and the transport TERMed it without its handler running (#122).
     */
    #[DataProvider('messagesWhoseRestoreRaisesAnError')]
    #[Test]
    public function decode_OfAMessageWhoseRestoreRaisesAnError_DecodesAndPassesTheErrorOn(object $message, int $type, string $error): void
    {
        $envelope = new Envelope($message, [new BusNameStamp('bus')]);
        $encoded = $this->serializer->encode($envelope);

        [$decoded, $seen] = RecordedErrors::during(fn (): Envelope => $this->serializer->decode($encoded));

        $this->assertEquals($envelope, $decoded);
        $this->assertSame([[$type, $error]], $seen);
    }

    /**
     * @return iterable<string, array{object, int, string}>
     */
    public static function messagesWhoseRestoreRaisesAnError(): iterable
    {
        yield 'a suppressed warning in __wakeup()' => [
            new SuppressedWarningOnWakeup('invoice 7'),
            E_WARNING,
            'hex2bin(): Hexadecimal input string must have an even length',
        ];
        yield 'a notice in __wakeup()' => [new NoticeOnWakeup('invoice 8'), E_USER_NOTICE, 'rebuilt a lazy field'];
        yield 'a notice in __unserialize()' => [new NoticeOnUnserialize('invoice 9'), E_USER_NOTICE, 'restored from an older format'];
        // An unserialize() call too, but one inside the decoder's: PHP 8.3 made its notice a warning.
        yield 'an unserialize() of its own cache in __wakeup()' => [
            new OwnCacheOnWakeup('invoice 10'),
            PHP_VERSION_ID >= 80300 ? E_WARNING : E_NOTICE,
            'unserialize(): Error at offset 0 of 20 bytes',
        ];
    }

    /**
     * A message whose class an autoloader loads during the decode, raising a warning it suppresses, decodes, and the
     * warning goes to the error handler there was (#122).
     */
    #[Test]
    public function decode_OfAMessageWhoseClassAnAutoloaderLoadsWithAWarning_DecodesAndPassesTheWarningOn(): void
    {
        ['body' => $body, 'class' => $class] = AutoloadedInvoice::inABodyUnderANewName('invoice 11', $this->serializer);
        $loader = AutoloadedInvoice::loaderWithASuppressedWarning($class);
        spl_autoload_register($loader);

        try {
            [$decoded, $seen] = RecordedErrors::during(fn (): Envelope => $this->serializer->decode(['body' => $body]));
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertEquals(new Envelope(new AutoloadedInvoice('invoice 11')), $decoded);
        $this->assertSame([[E_WARNING, sprintf('Undefined array key "%s"', $class)]], $seen);
    }

    /**
     * A payload igbinary cannot read, whatever is wrong with it, is a MessageDecodingFailedException with igbinary's own
     * warning, under an error handler that lets warnings go as under one that throws them, and with none: the warning
     * comes from the igbinary_unserialize() call of the decoder itself, and no handler sees it.
     */
    #[DataProvider('payloadsIgbinaryCannotReadUnderEachErrorHandler')]
    #[Test]
    public function decode_OfAPayloadIgbinaryCannotRead_ThrowsMessageDecodingFailedWithItsWarning(string $damage, string $warning, string $handler): void
    {
        $body = igbinary_serialize(new Envelope(new \stdClass()));
        $body = match ($damage) {
            'header' => 'invalid serialized data',
            'truncated' => substr((string) $body, 0, -3),
            'trailing byte' => $body . "\x00",
        };
        $seen = [];
        set_error_handler(match ($handler) {
            'lets warnings go' => static function (int $type, string $message) use (&$seen): bool {
                $seen[] = $message;

                return true;
            },
            'throws them' => static function (int $type, string $message) use (&$seen): never {
                $seen[] = $message;

                throw new \ErrorException($message, 0, $type);
            },
            'none' => null,
        });

        try {
            $this->serializer->decode(['body' => $body]);
            $this->fail('decode() should have failed');
        } catch (MessageDecodingFailedException $failure) {
            $this->assertStringStartsWith('Could not decode the envelope: igbinary_unserialize', $failure->getMessage());
            $this->assertStringContainsString($warning, $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $seen);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function payloadsIgbinaryCannotReadUnderEachErrorHandler(): iterable
    {
        $damages = [
            'header' => 'unsupported version',
            'truncated' => 'end-of-data',
            'trailing byte' => 'received more data to unserialize than expected',
        ];
        foreach ($damages as $damage => $warning) {
            foreach (['lets warnings go', 'throws them', 'none'] as $handler) {
                yield sprintf('%s, under a handler that %s', $damage, $handler) => [$damage, $warning, $handler];
            }
        }
    }

    /**
     * A subclass, in another file, whose deserialize() raises a warning itself: the warning is the decoder's, and the
     * decoding failure.
     */
    #[Test]
    public function decode_OfASubclassWhoseDeserializeRaisesAWarning_ThrowsMessageDecodingFailed(): void
    {
        $serializer = new class extends IgbinarySerializer {
            protected function deserialize(string $data): mixed
            {
                trigger_error('the payload comes from an older producer', E_USER_WARNING);

                return parent::deserialize($data);
            }
        };

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Could not decode the envelope: the payload comes from an older producer');

        $serializer->decode($serializer->encode(new Envelope(new \stdClass())));
    }

    /**
     * A decode that runs while an unserialize() of the application runs, from the __wakeup() of an object it restores,
     * reads only the calls of its own decode: igbinary's warning about a payload that is not igbinary is still the
     * decoding failure, though another unserialize() is running further out.
     */
    #[Test]
    public function decode_InsideAnUnserializeOfTheApplication_StillFailsOnIgbinarysWarning(): void
    {
        [$restored, $seen] = RecordedErrors::during(
            static fn (): mixed => unserialize(serialize(new DecodesOnWakeup('invalid serialized data'))),
        );

        $this->assertInstanceOf(DecodesOnWakeup::class, $restored);
        $this->assertInstanceOf(MessageDecodingFailedException::class, $restored->decoded);
        $this->assertStringStartsWith('Could not decode the envelope: igbinary_unserialize_header', $restored->decoded->getMessage());
        $this->assertSame([], $seen);
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