<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use IDCT\NATS\Auth\NonceSignerInterface;
use IDCT\NatsMessenger\EventListener\AddUnrecoverableFailureStampListener;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

/**
 * Symfony bundle for the NATS JetStream transport
 *
 * Registers the library's services in a Symfony application, so that a `nats-jetstream://` DSN works without
 * services.yaml entries: {@see NatsTransportFactory}, tagged `messenger.transport_factory`, the tag Messenger
 * finds transport factories by (nothing registers a factory from vendor/ on its own), and
 * {@see IgbinarySerializer}, which a transport uses only when its `serializer:` key names it. Registering the
 * serializer does not select it. The factory gets the application's `logger` service when there is one, so the
 * NATS client's lifecycle and errors, and the transport's own warning about Symfony's retry strategy in nats mode,
 * reach the application's log under Messenger's channel. A service that implements the client's
 * `NonceSignerInterface` is tagged `idct_nats_messenger.nonce_signer` when it is autoconfigured, and the factory gets
 * a locator of the services with that tag, which a transport's `nonce_signer` option names one of. It also registers
 * {@see AddUnrecoverableFailureStampListener}, tagged `kernel.event_subscriber`, which tells a transport in nats mode
 * whether every handler of a failed message failed for good.
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
     * Symfony runs every bundle's build(), then the kernel's, and loads the extensions after both, each into a
     * container of its own that it then merges into the application's. merge() refuses an interface that is
     * autoconfigured on both sides, so the bundle registers the signers' interface here, in the application's
     * container: an application that autoconfigures it in its own build() gets the same definition, with both tags.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // A service of the application that signs the server's nonce, so that the seed stays out of this process, gets
        // the tag the factory's locator collects, and a transport names it by its id in nonce_signer.
        $container->registerForAutoconfiguration(NonceSignerInterface::class)
            ->addTag(NatsTransportConfigurationBuilder::NONCE_SIGNER_TAG);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        // The application's logger, when it has one, goes to the NATS client of every transport, under Messenger's
        // channel: the connection's lifecycle at info level, its errors at error level. The transport warns through
        // it too, once, when Symfony's retry strategy is on in nats mode. The nonce signers are looked up by the
        // service id a transport's nonce_signer option holds.
        $services->set(NatsTransportFactory::class)
            ->args([
                service('logger')->nullOnInvalid(),
                tagged_locator(NatsTransportConfigurationBuilder::NONCE_SIGNER_TAG),
            ])
            ->tag('messenger.transport_factory')
            ->tag('monolog.logger', ['channel' => 'messenger']);

        // Under this one id only, see the class comment.
        $services->set(IgbinarySerializer::class);

        // Tells nats mode whether every handler of a failed message failed for good, by Symfony's rule (#125).
        $services->set(AddUnrecoverableFailureStampListener::class)
            ->tag('kernel.event_subscriber');
    }
}
