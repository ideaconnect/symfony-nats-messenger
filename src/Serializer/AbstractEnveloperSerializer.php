<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Base serializer for messenger envelopes.
 *
 * Provides the common encode/decode structure expected by Symfony Messenger's transport layer.
 * Concrete serializers only need to implement {@see serialize()} and {@see deserialize()}
 * for the raw payload format (e.g. igbinary, JSON, msgpack).
 *
 * The decode path validates that the encoded envelope contains a non-empty 'body' key
 * and that deserialization produces a valid Envelope instance. Every way its decoder fails is a
 * MessageDecodingFailedException, as with Symfony's own serializers, which is what a
 * transport settles as a message that cannot be decoded. A warning or notice of the application's
 * code that unserializing runs (a __wakeup(), an autoloader) goes to the application's error handler.
 *
 * @see IgbinarySerializer Concrete implementation using igbinary.
 */
abstract class AbstractEnveloperSerializer implements SerializerInterface
{
    /**
     * The PHP functions that parse a payload when {@see deserialize()} calls them: igbinary's, which
     * IgbinarySerializer calls, and PHP's own, which the README's custom serializer calls.
     */
    private const PARSERS = ['igbinary_unserialize', 'unserialize'];

    /**
     * Decodes a transport payload into an envelope.
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        // A single gate for a usable body: '?? null' folds the missing-key case into the same check
        // that rejects a null, non-string, or empty body - all of which are equally undecodable and
        // share one error message. A separate array_key_exists() guard would be redundant.
        $body = $encodedEnvelope['body'] ?? null;
        if (!is_string($body) || $body === '') {
            throw new MessageDecodingFailedException('Encoded envelope should at least have a "body".');
        }

        $envelope = $this->deserializeOrFail($body);

        if (!$envelope instanceof Envelope) {
            throw new MessageDecodingFailedException('Deserialized data is not a valid Symfony Messenger Envelope.');
        }

        return $envelope;
    }

    /**
     * {@see deserialize()}, with every way the decoder fails made a MessageDecodingFailedException, as Symfony's
     * PhpSerializer does: an exception it throws, and a warning or notice the decoder raises itself, which Symfony's
     * error handler throws as an ErrorException in debug mode. igbinary warns about a payload that is not igbinary,
     * and PHP's unserialize() (8.3 and later) about bytes after the end of the payload, whose value it still returns.
     * A transport takes only that exception for a message that cannot be decoded, as Symfony's own transports do, and
     * lets any other through, with the message left for redelivery.
     *
     * Unserializing a message also runs the application's code, which may raise warnings and notices of its own, even
     * ones it suppresses: the __wakeup() or __unserialize() of the message and of every object in it, and the
     * autoloader that loads their classes. They say nothing about the payload, so they go to the error handler there
     * was, as up to 5.9.x (#122). Which code raised one is read from the call stack ({@see raisedByTheDecoder()}).
     * Every deprecation goes to that handler too. With none there, or when it returns false, PHP's own handler takes
     * it. PHP gives that handler back without the error levels it was set for, so it is called for every level: one
     * set for E_WARNING alone gets an E_USER_NOTICE too. An exception it throws propagates as it is, not as a decode
     * failure, and the message is left for redelivery, as with any other exception of a serializer.
     */
    private function deserializeOrFail(string $body): mixed
    {
        $previous = null;
        /** @var \Throwable|null $applicationError The exception the error handler there was threw, if any. */
        $applicationError = null;
        // PHP calls an error handler with the error's type, message, file and line.
        $previous = set_error_handler(function (int $type, string $message, string $file, int $line) use (&$previous, &$applicationError): bool {
            if ($type !== E_DEPRECATED && $type !== E_USER_DEPRECATED
                && $this->raisedByTheDecoder(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS))
            ) {
                throw new MessageDecodingFailedException('Could not decode the envelope: ' . $message);
            }

            if ($previous === null) {
                return false;
            }

            try {
                return $previous($type, $message, $file, $line) !== false;
            } catch (\Throwable $thrown) {
                $applicationError = $thrown;

                throw $thrown;
            }
        });

        try {
            return $this->deserialize($body);
        } catch (\Throwable $failure) {
            if ($failure === $applicationError || $failure instanceof MessageDecodingFailedException) {
                throw $failure;
            }

            throw new MessageDecodingFailedException('Could not decode the envelope: ' . $failure->getMessage(), 0, $failure);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether the warning or notice being handled was raised by the decoder, read from the error handler's call
     * stack, out to this decode's {@see deserializeOrFail()}:
     *
     * - While igbinary_unserialize() or unserialize() runs, the outermost of those calls is the decoder. A warning
     *   it raises itself is the decoder's, and one raised by code running inside it, a __wakeup(), __unserialize()
     *   or Serializable::unserialize(), an autoloader, an unserialize() of their own, is the application's.
     * - Otherwise the code that raised it is the decoder when it runs on this serializer object: deserialize(),
     *   inherited or not, a method of the serializer it calls, or a closure of theirs. The code of another object, a
     *   codec helper among them, is the application's: such a helper throws a MessageDecodingFailedException itself
     *   for a payload it cannot decode, rather than going on with what it could read.
     *
     * Not the file or the lines of deserialize(): an override that calls parent::deserialize() and a codec helper
     * call the parser elsewhere, and a closure declared in deserialize() can run inside the parser, as an autoloader.
     *
     * @param list<array{function: string, line?: int, file?: string, class?: class-string, type?: '->'|'::', args?: list<mixed>, object?: object}> $frames
     *     The error handler's frame first, which has a file when PHP code raised the error, in the frame after it, and
     *     none when an internal function did, called from the frame after that.
     */
    private function raisedByTheDecoder(array $frames): bool
    {
        $decoding = [];
        foreach (array_slice($frames, 1) as $frame) {
            if ($frame['function'] === 'deserializeOrFail' && ($frame['object'] ?? null) === $this) {
                break;
            }

            $decoding[] = $frame;
        }

        $parser = null;
        foreach ($decoding as $depth => $frame) {
            if (!isset($frame['class']) && in_array($frame['function'], self::PARSERS, true)) {
                $parser = $depth;
            }
        }

        if ($parser !== null) {
            return $parser === 0;
        }

        $running = $decoding[isset($frames[0]['file']) ? 0 : 1] ?? [];

        return ($running['object'] ?? null) === $this;
    }

    /**
     * Encodes an envelope to transport payload shape.
     *
     * Stamps marked {@see NonSendableStampInterface} are removed first, as Symfony's own serializers do: they
     * describe the envelope's journey through this process and are not meant to travel. One of them, the
     * AckStamp a worker adds to every message it handles, holds a closure, which cannot be serialized. Every
     * re-send of a received message - Symfony's retry, or its failure transport - failed on it and stopped
     * the worker before it could reject the message, which JetStream then redelivered into the same failure,
     * over and over (#46).
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $envelope = $envelope->withoutStampsOfType(NonSendableStampInterface::class);

        return [
            'body' => $this->serialize($envelope),
            'headers' => $this->headers($envelope),
        ];
    }

    /**
     * Returns headers to include with the transport message.
     *
     * Override in concrete serializers to propagate envelope metadata as NATS headers.
     * Default implementation returns an empty array (no transport-level headers).
     *
     * @return array<string, string>
     */
    protected function headers(Envelope $envelope): array
    {
        return [];
    }

    /**
     * Serializes an envelope into raw binary/text payload.
     */
    abstract protected function serialize(Envelope $envelope): string;

    /**
     * Deserializes raw payload back to envelope-compatible data.
     *
     * An exception it throws, and a warning or notice raised by its igbinary_unserialize() or unserialize() call or by
     * its own code, make the payload one that cannot be decoded. One raised by code the parser runs, or by another
     * object it calls, goes to the application's error handler ({@see deserializeOrFail()}).
     */
    abstract protected function deserialize(string $data): mixed;
}