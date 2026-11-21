<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * A message whose class an autoloader loads while the message is decoded (#122).
 */
final class AutoloadedInvoice
{
    public function __construct(public readonly string $text)
    {
    }

    /**
     * The body the serializer encodes an envelope with this message into, with the class named by a name of the same
     * length that nothing has declared yet, and that name: an autoloader declares it, as an alias of this class, when
     * the decode asks for it. Each call gives a new name, so the autoloader runs whatever ran before.
     *
     * @return array{body: string, class: string}
     */
    public static function inABodyUnderANewName(string $text, SerializerInterface $serializer): array
    {
        $class = __NAMESPACE__ . '\\A' . bin2hex(random_bytes(8));
        $body = $serializer->encode(new Envelope(new self($text)))['body'];

        return ['body' => str_replace(self::class, $class, $body), 'class' => $class];
    }

    /**
     * An autoloader that declares the class, after it looked the class up in a class map a cache left empty, with @,
     * which raises a warning all the same.
     *
     * @return \Closure(string): void
     */
    public static function loaderWithASuppressedWarning(string $class): \Closure
    {
        return static function (string $requested) use ($class): void {
            if ($requested === $class) {
                $classMap = [];
                @$classMap[$requested];
                class_alias(self::class, $class);
            }
        };
    }
}
