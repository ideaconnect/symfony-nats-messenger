<?php

namespace IDCT\NatsMessenger;

use IDCT\NatsMessenger\NatsTransport;
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
 * The factory uses igbinary serialization instead of Symfony's provided serializer
 * for performance optimization. igbinary is significantly faster and more compact
 * than standard PHP serialization.
 *
 * DSN Format: nats-jetstream://[user:pass@]host:port/stream_name/topic_name
 */
class NatsTransportFactory implements TransportFactoryInterface
{
    /**
     * DSN scheme prefix for NATS JetStream transports.
     * Used to identify which transports this factory should handle.
     */
    private const SCHEME = 'nats-jetstream://';

    /**
     * Create a new NATS transport instance.
     *
     * This method instantiates a NatsTransport with the provided DSN and options.
     *
     * Note: The $serializer parameter is intentionally ignored in favor of igbinary
     * serialization for performance reasons. NATS JetStream messages are serialized
     * using igbinary directly for optimal speed and memory efficiency.
     *
     * @param string $dsn The NATS JetStream DSN (marked sensitive for security)
     * @param array $options Transport configuration options
     * @param SerializerInterface $serializer Symfony serializer (unused - igbinary is used instead)
     * @return TransportInterface A new NatsTransport instance
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        // This transport uses igbinary serialization for performance optimization
        // instead of the provided Symfony serializer, so we instantiate NatsTransport directly
        // with the DSN and options, bypassing the serializer parameter
        return new NatsTransport($dsn, $options);
    }

    /**
     * Check if this factory can handle the given DSN.
     *
     * This method is called by Symfony's transport registry to determine if this factory
     * should be used to create a transport for the provided DSN.
     *
     * @param string $dsn The DSN to check (marked sensitive for security)
     * @param array $options Transport configuration options (unused but required by interface)
     * @return bool True if the DSN scheme matches NATS JetStream, false otherwise
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return 0 === strpos($dsn, self::SCHEME);
    }
}