<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class IDCTNatsMessengerBundle extends AbstractBundle
{
    /**
     * @param array<array-key, mixed> $config
     * @param ContainerConfigurator $configurator
     * @param ContainerBuilder $container
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        $services->set('idct_nats_messenger.transport_factory', NatsTransportFactory::class)
            ->tag('messenger.transport_factory');

        // One id only: Symfony 7.4+ wraps a transport's serializer for signing once per id the transports
        // name, so an alias next to this id would wrap it twice and break signed messages.
        $services->set(IgbinarySerializer::class);
    }
}
