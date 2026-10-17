<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Symfony bundle for the NATS JetStream transport
 *
 * Registers the library's services in a Symfony application, so that a `nats-jetstream://` DSN works without
 * services.yaml entries: {@see NatsTransportFactory}, tagged `messenger.transport_factory`, the tag Messenger
 * finds transport factories by (nothing registers a factory from vendor/ on its own), and
 * {@see IgbinarySerializer}, which a transport uses only when its `serializer:` key names it. Registering the
 * serializer does not select it. The factory gets the application's `logger` service when there is one, so the
 * NATS client's lifecycle and errors reach the application's log under Messenger's channel.
 *
 * Each service has one id, its class name. Applications write that id in their configuration, and an entry an
 * application defines under it, as it had to before this bundle, replaces the bundle's. The serializer must not
 * get a second id: Symfony 7.4+ wraps the serializer a transport names in a SigningSerializer once for each id
 * in the transports' configuration, so a transport naming the second id would get two of them and could never
 * decode a message that must be signed.
 *
 * Symfony Flex enables the bundle by guessing this class from the package's PSR-4 prefix and finding the name
 * of Symfony's AbstractBundle in this file, so the class name, its place in src/ and the import of
 * AbstractBundle above must stay as they are.
 */
final class IDCTNatsMessengerBundle extends AbstractBundle
{
    /**
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        // The application's logger, when it has one, goes to the NATS client of every transport, under Messenger's
        // channel: the connection's lifecycle at info level, its errors at error level.
        $services->set(NatsTransportFactory::class)
            ->args([service('logger')->nullOnInvalid()])
            ->tag('messenger.transport_factory')
            ->tag('monolog.logger', ['channel' => 'messenger']);

        // Under this one id only, see the class comment.
        $services->set(IgbinarySerializer::class);
    }
}
