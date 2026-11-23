<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Serializer;

use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use IDCT\NatsMessenger\Tests\Support\AutoloadedInvoice;
use IDCT\NatsMessenger\Tests\Support\NoticeOnWakeup;
use IDCT\NatsMessenger\Tests\Support\OwnCacheOnWakeup;
use IDCT\NatsMessenger\Tests\Support\RecordedErrors;
use IDCT\NatsMessenger\Tests\Support\SuppressedWarningOnWakeup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
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

/**
 * A message declared in the same file as the README's serializer, whose __wakeup() raises a warning it suppresses.
 * The warning comes from this file, as the README's unserialize() call does, and is still the application's (#122).
 */
final class SameFileSuppressedWarningOnWakeup
{
    public function __construct(public readonly string $text)
    {
    }

    public function __wakeup(): void
    {
        @hex2bin('abc');
    }
}

/**
 * A message declared in the same file as the README's serializer, whose __unserialize() raises a notice (#122).
 */
final class SameFileNoticeOnUnserialize
{
    public function __construct(public readonly string $text)
    {
    }

    /** @return array{text: string} */
    public function __serialize(): array
    {
        return ['text' => $this->text];
    }

    /** @param array{text: string} $data */
    public function __unserialize(array $data): void
    {
        trigger_error('restored from an older format', E_USER_NOTICE);
        $this->text = $data['text'];
    }
}

/**
 * The README's serializer, with a deserialize() that calls it through parent::deserialize(): the unserialize() call
 * runs in the parent's deserialize(), not in the one that runs first.
 */
final class ParentCallingSerializer extends ReadmeExampleSerializer
{
    protected function deserialize(string $data): mixed
    {
        return parent::deserialize($data);
    }
}

/**
 * A codec helper, another object than the serializer, that calls PHP's unserialize() for it.
 */
final class PhpCodec
{
    public function unserialize(string $data): mixed
    {
        return unserialize($data);
    }
}

/**
 * A codec helper that reads the version of the payload from a header its producer did not send, which PHP warns about
 * as a missing key, and goes on with the payload. Its method is named unserialize(), as PHP's function is.
 */
final class HeaderReadingCodec
{
    public function unserialize(string $data): mixed
    {
        $header = [];
        $header['version'];

        return unserialize($data);
    }
}

/**
 * A serializer that hands the payload to a codec helper.
 */
final class CodecHelperSerializer extends AbstractEnveloperSerializer
{
    public function __construct(private readonly PhpCodec|HeaderReadingCodec $codec)
    {
    }

    protected function serialize(Envelope $envelope): string
    {
        return serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        return $this->codec->unserialize($data);
    }
}

/**
 * A serializer whose deserialize() registers an autoloader of its own for its unserialize() call: a closure declared
 * inside deserialize(), which looks the class up in a class map a cache left empty, with @, and declares it as an
 * alias of the class given for it.
 */
final class ClassLoadingSerializer extends AbstractEnveloperSerializer
{
    /** @param array<string, class-string> $aliases */
    public function __construct(private readonly array $aliases)
    {
    }

    protected function serialize(Envelope $envelope): string
    {
        return serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        $loader = function (string $class): void {
            $classMap = [];
            @$classMap[$class];
            if (isset($this->aliases[$class])) {
                class_alias($this->aliases[$class], $class);
            }
        };
        spl_autoload_register($loader);

        try {
            return unserialize($data);
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}

/**
 * A serializer whose deserialize() raises a notice itself, about the payload it reads.
 */
class NoticingSerializer extends AbstractEnveloperSerializer
{
    protected function serialize(Envelope $envelope): string
    {
        return serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        trigger_error('offset 3 of 9', E_USER_NOTICE);

        return unserialize($data);
    }
}

/**
 * A serializer that inherits its deserialize() from another.
 */
final class InheritingNoticingSerializer extends NoticingSerializer
{
}

/**
 * A serializer that reads a version and a payload, split by "|", from the body: a body without the "|" lacks the
 * payload, which PHP warns about as a missing key.
 */
final class VersionedSerializer extends AbstractEnveloperSerializer
{
    protected function serialize(Envelope $envelope): string
    {
        return '1|' . serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        [, $payload] = explode('|', $data, 2);

        return unserialize((string) $payload);
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
            $this->assertSame(0, $failure->getCode(), 'as Symfony\'s PhpSerializer wraps one');
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
        error_clear_last();

        try {
            $decoded = $serializer->decode(['body' => 'x']);
            // The error handler there was is in place again.
            trigger_error('after decode()', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
        }

        $this->assertSame($envelope, $decoded);
        $this->assertSame([[E_USER_DEPRECATED, 'an old format'], [E_USER_DEPRECATED, 'after decode()']], $seen);
        // The handler there was took it, so PHP's own handler did not run after it.
        $this->assertNull(error_get_last());
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

    /**
     * With the README's serializer, a message whose __wakeup() or __unserialize() raises a warning or a notice decodes,
     * and the error goes to the error handler there was, also for a message declared in the same file as the
     * serializer: the error comes from the application's code, not from the decoder's unserialize() call. 5.10.0 made
     * every such message one that cannot be decoded (#122).
     */
    #[DataProvider('messagesWhoseRestoreRaisesAnError')]
    #[Test]
    public function readmeCustomSerializerExample_OfAMessageWhoseRestoreRaisesAnError_DecodesAndPassesTheErrorOn(object $message, int $type, string $error): void
    {
        $serializer = new ReadmeExampleSerializer();
        $envelope = new Envelope($message, [new BusNameStamp('readme-bus')]);
        $encoded = $serializer->encode($envelope);

        [$decoded, $seen] = RecordedErrors::during(static fn (): Envelope => $serializer->decode($encoded));

        $this->assertEquals($envelope, $decoded);
        $this->assertSame([[$type, $error]], $seen);
    }

    /**
     * @return iterable<string, array{object, int, string}>
     */
    public static function messagesWhoseRestoreRaisesAnError(): iterable
    {
        yield 'a suppressed warning in __wakeup(), the class in the serializer\'s file' => [
            new SameFileSuppressedWarningOnWakeup('invoice 7'),
            E_WARNING,
            'hex2bin(): Hexadecimal input string must have an even length',
        ];
        yield 'a notice in __unserialize(), the class in the serializer\'s file' => [
            new SameFileNoticeOnUnserialize('invoice 8'),
            E_USER_NOTICE,
            'restored from an older format',
        ];
        yield 'a suppressed warning in __wakeup()' => [
            new SuppressedWarningOnWakeup('invoice 9'),
            E_WARNING,
            'hex2bin(): Hexadecimal input string must have an even length',
        ];
        yield 'a notice in __wakeup()' => [new NoticeOnWakeup('invoice 10'), E_USER_NOTICE, 'rebuilt a lazy field'];
        // An unserialize() call too, but one inside the decoder's: PHP 8.3 made its notice a warning.
        yield 'an unserialize() of its own cache in __wakeup()' => [
            new OwnCacheOnWakeup('invoice 11'),
            PHP_VERSION_ID >= 80300 ? E_WARNING : E_NOTICE,
            'unserialize(): Error at offset 0 of 20 bytes',
        ];
    }

    /**
     * With the README's serializer, a message whose class an autoloader loads during the decode, raising a warning it
     * suppresses, decodes, and the warning goes to the error handler there was (#122).
     */
    #[Test]
    public function readmeCustomSerializerExample_OfAMessageWhoseClassAnAutoloaderLoadsWithAWarning_DecodesAndPassesTheWarningOn(): void
    {
        $serializer = new ReadmeExampleSerializer();
        ['body' => $body, 'class' => $class] = AutoloadedInvoice::inABodyUnderANewName('invoice 12', $serializer);
        $loader = AutoloadedInvoice::loaderWithASuppressedWarning($class);
        spl_autoload_register($loader);

        try {
            [$decoded, $seen] = RecordedErrors::during(static fn (): Envelope => $serializer->decode(['body' => $body]));
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertEquals(new Envelope(new AutoloadedInvoice('invoice 12')), $decoded);
        $this->assertSame([[E_WARNING, sprintf('Undefined array key "%s"', $class)]], $seen);
    }

    /**
     * PHP's unserialize() warns about bytes after the end of a payload since PHP 8.3, and returns the envelope all the
     * same: the warning is the decoder's, and the decoding failure, wherever the serializer calls unserialize() from,
     * an override that calls parent::deserialize() or a codec helper, and though the error handler there was lets
     * warnings go. A check of the file and lines of the deserialize() that runs first delivered the message (#122).
     */
    #[DataProvider('serializersCallingUnserializeElsewhere')]
    #[RequiresPhp('>= 8.3.0')]
    #[Test]
    public function decode_OfAPayloadWithATrailingByte_ThrowsMessageDecodingFailedWhereverUnserializeIsCalled(AbstractEnveloperSerializer $serializer): void
    {
        $body = $serializer->encode(new Envelope(new \stdClass()))['body'] . 'X';

        [$failure, $seen] = RecordedErrors::during(static function () use ($serializer, $body): ?MessageDecodingFailedException {
            try {
                $serializer->decode(['body' => $body]);
            } catch (MessageDecodingFailedException $failure) {
                return $failure;
            }

            return null;
        });

        $this->assertInstanceOf(MessageDecodingFailedException::class, $failure);
        $this->assertSame(
            sprintf('Could not decode the envelope: unserialize(): Extra data starting at offset %d of %d bytes', strlen($body) - 1, strlen($body)),
            $failure->getMessage(),
        );
        $this->assertSame([], $seen);
    }

    /**
     * A payload PHP's unserialize() cannot read is a decoding failure with its warning (a notice before PHP 8.3),
     * wherever the serializer calls unserialize() from.
     */
    #[DataProvider('serializersCallingUnserializeElsewhere')]
    #[Test]
    public function decode_OfAPayloadCutShort_ThrowsMessageDecodingFailedWhereverUnserializeIsCalled(AbstractEnveloperSerializer $serializer): void
    {
        $body = substr($serializer->encode(new Envelope(new \stdClass()))['body'], 0, -3);

        [$failure, $seen] = RecordedErrors::during(static function () use ($serializer, $body): ?MessageDecodingFailedException {
            try {
                $serializer->decode(['body' => $body]);
            } catch (MessageDecodingFailedException $failure) {
                return $failure;
            }

            return null;
        });

        $this->assertInstanceOf(MessageDecodingFailedException::class, $failure);
        $this->assertStringStartsWith('Could not decode the envelope: unserialize(): Error at offset', $failure->getMessage());
        $this->assertSame([], $seen);
    }

    /**
     * @return iterable<string, array{AbstractEnveloperSerializer}>
     */
    public static function serializersCallingUnserializeElsewhere(): iterable
    {
        yield 'an override that calls parent::deserialize()' => [new ParentCallingSerializer()];
        yield 'a codec helper' => [new CodecHelperSerializer(new PhpCodec())];
    }

    /**
     * A message whose class an autoloader loads, the autoloader a closure declared inside deserialize() raising a
     * warning it suppresses, decodes: the closure runs inside unserialize(), as the application's code. A check of the
     * file and lines of deserialize() made it a decoding failure (#122).
     */
    #[Test]
    public function decode_OfAMessageWhoseClassAClosureOfDeserializeLoads_DecodesAndPassesTheWarningOn(): void
    {
        $probe = new ReadmeExampleSerializer();
        ['body' => $body, 'class' => $class] = AutoloadedInvoice::inABodyUnderANewName('invoice 13', $probe);
        $serializer = new ClassLoadingSerializer([$class => AutoloadedInvoice::class]);

        [$decoded, $seen] = RecordedErrors::during(static fn (): Envelope => $serializer->decode(['body' => $body]));

        $this->assertEquals(new Envelope(new AutoloadedInvoice('invoice 13')), $decoded);
        $this->assertSame([[E_WARNING, sprintf('Undefined array key "%s"', $class)]], $seen);
    }

    /**
     * A notice an inherited deserialize() raises itself is the decoder's, and the decoding failure.
     */
    #[Test]
    public function decode_WhoseInheritedDeserializeRaisesANotice_ThrowsMessageDecodingFailed(): void
    {
        $serializer = new InheritingNoticingSerializer();
        $encoded = $serializer->encode(new Envelope(new \stdClass()));

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Could not decode the envelope: offset 3 of 9');

        $serializer->decode($encoded);
    }

    /**
     * A warning PHP raises in the code of deserialize() itself, here about a part of the body that is not there, is the
     * decoder's, and the decoding failure.
     */
    #[Test]
    public function decode_WhoseDeserializeReadsAPartTheBodyLacks_ThrowsMessageDecodingFailed(): void
    {
        $serializer = new VersionedSerializer();
        $this->assertEquals(new Envelope(new \stdClass()), $serializer->decode($serializer->encode(new Envelope(new \stdClass()))));

        $this->expectException(MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Could not decode the envelope: Undefined array key 1');

        $serializer->decode(['body' => 'no version']);
    }

    /**
     * A warning the code of a codec helper, another object than the serializer, raises itself is the application's to
     * handle: it goes to the error handler there was, and the message decodes. A codec helper throws a
     * MessageDecodingFailedException itself for a payload it cannot decode.
     */
    #[Test]
    public function decode_WhoseCodecHelperRaisesAWarningItself_PassesItOnAndDecodes(): void
    {
        $serializer = new CodecHelperSerializer(new HeaderReadingCodec());
        $envelope = new Envelope(new \stdClass(), [new BusNameStamp('bus')]);
        $encoded = $serializer->encode($envelope);

        [$decoded, $seen] = RecordedErrors::during(static fn (): Envelope => $serializer->decode($encoded));

        $this->assertEquals($envelope, $decoded);
        $this->assertSame([[E_WARNING, 'Undefined array key "version"']], $seen);
    }

    /**
     * An exception the error handler there was throws for an error of the application's code propagates as it is, not
     * as a decoding failure, so a transport leaves the message for redelivery. PHP gives that handler back without the
     * error levels it was set for, so one set for E_WARNING alone is called for the E_USER_NOTICE too (#122).
     */
    #[DataProvider('levelsOfAnErrorHandlerThatThrows')]
    #[Test]
    public function decode_WhoseErrorHandlerThereWasThrows_PropagatesItsExceptionAsItIs(int $levels): void
    {
        $serializer = new ReadmeExampleSerializer();
        $encoded = $serializer->encode(new Envelope(new NoticeOnWakeup('invoice 14')));
        $thrown = null;
        set_error_handler(static function (int $type, string $message) use (&$thrown): never {
            throw $thrown = new \ErrorException($message, 0, $type);
        }, $levels);

        try {
            $serializer->decode($encoded);
            $this->fail('decode() should have failed');
        } catch (\Throwable $failure) {
            $this->assertInstanceOf(\ErrorException::class, $thrown);
            $this->assertSame($thrown, $failure);
            $this->assertSame('rebuilt a lazy field', $failure->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function levelsOfAnErrorHandlerThatThrows(): iterable
    {
        yield 'set for every level' => [E_ALL];
        yield 'set for E_WARNING alone' => [E_WARNING];
    }

    /**
     * An error of the application's code that the error handler there was does not take, returning false, or with no
     * handler there, goes to PHP's own handler, as without this, and the message decodes.
     */
    #[DataProvider('errorHandlersThatLeaveTheErrorToPhp')]
    #[Test]
    public function decode_WhoseErrorHandlerThereWasLeavesTheErrorToPhp_DecodesAndPhpRecordsIt(?\Closure $handler): void
    {
        $serializer = new ReadmeExampleSerializer();
        $envelope = new Envelope(new SuppressedWarningOnWakeup('invoice 15'));
        $encoded = $serializer->encode($envelope);
        set_error_handler($handler);
        error_clear_last();

        try {
            $decoded = $serializer->decode($encoded);
            $last = error_get_last();
        } finally {
            restore_error_handler();
        }

        $this->assertEquals($envelope, $decoded);
        $this->assertSame('hex2bin(): Hexadecimal input string must have an even length', $last['message'] ?? null);
    }

    /**
     * @return iterable<string, array{\Closure|null}>
     */
    public static function errorHandlersThatLeaveTheErrorToPhp(): iterable
    {
        yield 'a handler that returns false' => [static fn (): bool => false];
        yield 'no handler' => [null];
    }

    /**
     * The error handler there was is in place again after a decode that failed, as after one that succeeded.
     */
    #[Test]
    public function decode_ThatFailed_RestoresTheErrorHandlerThereWas(): void
    {
        $serializer = new NoticingSerializer();
        $encoded = $serializer->encode(new Envelope(new \stdClass()));

        [$failure, $seen] = RecordedErrors::during(static function () use ($serializer, $encoded): ?MessageDecodingFailedException {
            try {
                $serializer->decode($encoded);
            } catch (MessageDecodingFailedException $failure) {
                trigger_error('after decode()', E_USER_NOTICE);

                return $failure;
            }

            return null;
        });

        $this->assertInstanceOf(MessageDecodingFailedException::class, $failure);
        $this->assertSame([[E_USER_NOTICE, 'after decode()']], $seen);
    }
}
