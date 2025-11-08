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
     * Serializer instance for encoding and decoding messages.
     * Default will be set to igbinary in the NATS transport
     * if the serializer is not set.
     *
     * @var SerializerInterface|null
     */
    protected ?SerializerInterface $serializer = null;

    /**
     * Create a new NATS transport instance.
     *
     * This method instantiates a NatsTransport with the provided DSN and options.
     *
     * @param string $dsn The NATS JetStream DSN (marked sensitive for security)
     * @param array $options Transport configuration options
     * @param SerializerInterface $serializer Symfony serializer
     * @return TransportInterface A new NatsTransport instance
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new NatsTransport($dsn, $options, $this->serializer);
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

    /*
     * Set a custom serializer for the transport.
     *
     * @param SerializerInterface $serializer
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }
}