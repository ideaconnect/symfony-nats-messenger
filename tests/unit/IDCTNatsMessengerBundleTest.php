<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\IDCTNatsMessengerBundle;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use IDCT\NatsMessenger\Tests\Support\MessengerTestKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * The bundle's services as Symfony compiles them into an application. Each test boots a kernel with
 * FrameworkBundle and Messenger and takes the transports from its container, so it sees what loading the
 * bundle's extension alone does not: the tags autoconfiguration adds, the factory Messenger picks for a DSN,
 * and the decorators it puts around a transport's serializer.
 */
final class IDCTNatsMessengerBundleTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';

    private const NATS_DSN = 'nats-jetstream://localhost:4222/stream/topic';

    /** @var list<MessengerTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->remove();
        }
        $this->kernels = [];
    }

    #[Test]
    public function transportFactoryIsRegisteredAndTaggedForMessengerDiscovery(): void
    {
        $kernel = $this->kernel([new IDCTNatsMessengerBundle()], ['transports' => ['nats' => self::NATS_DSN]]);

        $this->assertInstanceOf(NatsTransport::class, $kernel->testContainer()->get('messenger.transport.nats'));
    }

    /**
     * Autoconfiguration tags a transport factory only when the application defines it as a service; nothing
     * registers one from vendor/ on its own. This is what the bundle is for.
     */
    #[Test]
    public function withoutTheBundleNoTransportFactorySupportsANatsDsn(): void
    {
        $kernel = $this->kernel([], ['transports' => ['nats' => self::NATS_DSN]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No transport supports Messenger DSN');

        $kernel->testContainer()->get('messenger.transport.nats');
    }

    #[Test]
    public function theReadmesIgbinaryExampleGivesTheTransportTheIgbinarySerializer(): void
    {
        $kernel = $this->kernel(
            [new IDCTNatsMessengerBundle()],
            [],
            [self::readmeYamlBlockAfter('#### Using IgbinarySerializer (recommended)')],
        );

        $chain = self::serializerChain($kernel->testContainer()->get('messenger.transport.nats_transport'));

        $this->assertSame(IgbinarySerializer::class, end($chain));
    }

    /**
     * @param list<BundleInterface> $bundles
     * @param array<string, mixed>  $messenger
     * @param list<string>          $files
     */
    private function kernel(array $bundles, array $messenger, array $files = []): MessengerTestKernel
    {
        $kernel = new MessengerTestKernel($bundles, $messenger, $files);
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /** The first ```yaml block in README.md after the given line. */
    private static function readmeYamlBlockAfter(string $line): string
    {
        $readme = (string) file_get_contents(self::README);

        $offset = strpos($readme, "\n" . $line . "\n");
        self::assertNotFalse($offset, sprintf('README.md has no line "%s".', $line));
        self::assertSame(1, preg_match('/```yaml\n(.*?)```/s', $readme, $block, 0, $offset));

        return $block[1];
    }

    /**
     * The serializer the transport holds, then each serializer it hands the work to, innermost last.
     *
     * @return list<class-string>
     */
    private static function serializerChain(mixed $transport): array
    {
        self::assertInstanceOf(NatsTransport::class, $transport);

        $chain = [];
        $serializer = (new ReflectionProperty(NatsTransport::class, 'serializer'))->getValue($transport);
        while ($serializer instanceof SerializerInterface) {
            $chain[] = $serializer::class;
            $serializer = self::wrappedSerializer($serializer);
        }

        return $chain;
    }

    /** The serializer a decorator, such as Symfony's SigningSerializer, hands the work to. */
    private static function wrappedSerializer(SerializerInterface $serializer): ?SerializerInterface
    {
        foreach ((new ReflectionObject($serializer))->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($serializer)) {
                continue;
            }

            $value = $property->getValue($serializer);
            if ($value instanceof SerializerInterface) {
                return $value;
            }
        }

        return null;
    }
}
