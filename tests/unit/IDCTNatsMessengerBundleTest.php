<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\IDCTNatsMessengerBundle;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use IDCT\NatsMessenger\Tests\Support\MessengerTestKernel;
use IDCT\NatsMessenger\Tests\Support\SignedMessage;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;

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

    /** Makes SignedMessage a message type Messenger signs, as `sign: true` on a handler does. */
    private const SIGNED_MESSAGE_HANDLER = <<<'YAML'
        services:
            IDCT\NatsMessenger\Tests\Support\SignedMessageHandler:
                tags:
                    - { name: messenger.message_handler, handles: IDCT\NatsMessenger\Tests\Support\SignedMessage, sign: true }
        YAML;

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
     * Symfony 7.4 and later wrap the serializer a transport names in a SigningSerializer, one for each service id
     * they find in the transports' configuration, compared as strings. Two ids for one serializer, an alias and
     * the service it points to, give the transport that names the alias two of them: the outer one checks the
     * signature and removes it, and the inner one then refuses a message that must be signed and has no
     * signature, so the transport can never decode such a message. Each id the bundle gives the serializer must
     * leave its transport with one SigningSerializer.
     */
    #[Test]
    #[RequiresMethod(SigningSerializer::class, 'decode')]
    public function aSignedMessageSurvivesTheSerializerUnderEveryIdTheBundleGivesIt(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('The igbinary extension is not available.');
        }

        $transports = [];
        foreach (self::idsOfTheBundlesSerializer() as $index => $id) {
            $transports['nats_' . $index] = ['dsn' => self::NATS_DSN . $index, 'serializer' => $id];
        }
        $container = $this
            ->kernel([new IDCTNatsMessengerBundle()], ['transports' => $transports], [self::SIGNED_MESSAGE_HANDLER])
            ->testContainer();

        foreach ($transports as $name => $transport) {
            $transportService = $container->get('messenger.transport.' . $name);
            $this->assertSame(
                [SigningSerializer::class, IgbinarySerializer::class],
                self::serializerChain($transportService),
                sprintf('The transport naming "%s" holds the serializer once wrapped for signing.', $transport['serializer']),
            );

            $serializer = self::serializerOf($transportService);
            $decoded = $serializer->decode($serializer->encode(new Envelope(new SignedMessage('hello'))));
            $this->assertEquals(new SignedMessage('hello'), $decoded->getMessage());
        }
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

    /**
     * Every service id the bundle's extension defines or aliases that leads to the IgbinarySerializer.
     *
     * @return list<string>
     */
    private static function idsOfTheBundlesSerializer(): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new IDCTNatsMessengerBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $container);

        $ids = array_values(array_filter(
            [...array_keys($container->getDefinitions()), ...array_keys($container->getAliases())],
            static function (string $id) use ($container): bool {
                while ($container->hasAlias($id)) {
                    $id = (string) $container->getAlias($id);
                }

                // A definition without a class takes its id as the class when the container is compiled.
                return ($container->getDefinition($id)->getClass() ?? $id) === IgbinarySerializer::class;
            },
        ));
        self::assertNotSame([], $ids, 'The bundle registers the IgbinarySerializer.');

        return $ids;
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
        $chain = [];
        $serializer = self::serializerOf($transport);
        while ($serializer instanceof SerializerInterface) {
            $chain[] = $serializer::class;
            $serializer = self::wrappedSerializer($serializer);
        }

        return $chain;
    }

    /** The serializer the transport encodes and decodes with. */
    private static function serializerOf(mixed $transport): SerializerInterface
    {
        self::assertInstanceOf(NatsTransport::class, $transport);

        $serializer = (new ReflectionProperty(NatsTransport::class, 'serializer'))->getValue($transport);
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
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
