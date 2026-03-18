<?php

namespace IDCT\NatsMessenger;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * NATS JetStream Transport Factory
 *
 * This factory creates NATS JetStream transport instances for Symfony Messenger.
 * It implements the TransportFactoryInterface to integrate seamlessly with
 * Symfony's messenger transport discovery and instantiation system.
 *
 * The factory passes Symfony's resolved serializer into the transport. When no
 * serializer service is configured for the transport, the transport falls back
 * to its default igbinary serializer.
 *
 * DSN Format: nats-jetstream://[user:pass@]host:port/stream_name/topic_name
 *
 * @implements TransportFactoryInterface<NatsTransport>
 */
class NatsTransportFactory implements TransportFactoryInterface
{
    /**
     * DSN scheme prefix for NATS JetStream transports.
     * Used to identify which transports this factory should handle.
     */
    private const SCHEME = 'nats-jetstream://';

    /** DSN scheme prefix for TLS-enabled NATS JetStream transports. */
    private const TLS_SCHEME = 'nats-jetstream+tls://';

    /**
     * Create a new NATS transport instance.
     *
    * This method instantiates a NatsTransport with the provided DSN, options,
    * and serializer resolved by Symfony Messenger.
     *
     * @param string $dsn The NATS JetStream DSN (marked sensitive for security)
    * @param array<string, mixed> $options Transport configuration options
     * @param SerializerInterface $serializer Symfony serializer
     * @return TransportInterface A new NatsTransport instance
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new NatsTransport($dsn, $options, $serializer);
    }

    /**
     * Check if this factory can handle the given DSN.
     *
     * This method is called by Symfony's transport registry to determine if this factory
     * should be used to create a transport for the provided DSN.
     *
     * @param string $dsn The DSN to check (marked sensitive for security)
    * @param array<string, mixed> $options Transport configuration options (unused but required by interface)
     * @return bool True if the DSN scheme matches NATS JetStream, false otherwise
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME) || str_starts_with($dsn, self::TLS_SCHEME);
    }
}