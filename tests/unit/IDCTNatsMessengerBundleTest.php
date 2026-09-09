<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NatsMessenger\IDCTNatsMessengerBundle;
use IDCT\NatsMessenger\NatsTransportFactory;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class IDCTNatsMessengerBundleTest extends TestCase
{
    #[Test]
    public function transportFactoryIsRegisteredAndTaggedForMessengerDiscovery(): void
    {
        $container = self::buildContainer();

        $this->assertTrue($container->hasDefinition('idct_nats_messenger.transport_factory'));

        $definition = $container->getDefinition('idct_nats_messenger.transport_factory');
        $this->assertSame(NatsTransportFactory::class, $definition->getClass());
        $this->assertTrue($definition->hasTag('messenger.transport_factory'));
    }

    #[Test]
    public function igbinarySerializerIsRegisteredWithClassNameAlias(): void
    {
        $container = self::buildContainer();

        $this->assertTrue($container->hasDefinition('idct_nats_messenger.serializer.igbinary'));

        $definition = $container->getDefinition('idct_nats_messenger.serializer.igbinary');
        $this->assertSame(IgbinarySerializer::class, $definition->getClass());

        $this->assertTrue($container->hasAlias(IgbinarySerializer::class));
        $this->assertSame('idct_nats_messenger.serializer.igbinary', (string) $container->getAlias(IgbinarySerializer::class));
    }

    private static function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new IDCTNatsMessengerBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $container);

        return $container;
    }
}
