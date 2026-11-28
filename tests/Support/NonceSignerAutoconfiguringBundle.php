<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NATS\Auth\NonceSignerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Another bundle that autoconfigures the client's NonceSignerInterface in its extension, with a tag of its own, as a
 * bundle for a NATS client used beside the transport might.
 */
final class NonceSignerAutoconfiguringBundle extends AbstractBundle
{
    public const TAG = 'other_bundle.nonce_signer';

    /**
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(NonceSignerInterface::class)->addTag(self::TAG);
    }
}
