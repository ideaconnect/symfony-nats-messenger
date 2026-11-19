<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use IDCT\NatsMessenger\Options\GiveUpVia;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * NATS JetStream Transport Factory
 *
 * Implements TransportFactoryInterface so Symfony Messenger's transport registry discovers and
 * instantiates {@see NatsTransport} for the recognized DSN schemes (see supports()).
 *
 * The factory hands its logger, when it has one, to every transport it creates, for the NATS client and for the
 * transport's own warning in nats mode (see the constructor). The factory always passes Symfony's resolved
 * serializer into the transport (the interface signature is non-nullable). When a transport configures no serializer,
 * Symfony injects its default serializer service - the native PhpSerializer unless
 * reconfigured - never null, so the transport's built-in igbinary auto-default only
 * applies when {@see NatsTransport} is constructed directly with a null serializer.
 *
 * DSN Format: nats-jetstream://[user:pass@]host:port/stream_name/topic_name
 *
 * @implements TransportFactoryInterface<NatsTransport>
 */
class NatsTransportFactory implements TransportFactoryInterface
{
    /**
     * @param LoggerInterface|null $logger Handed to every transport this factory creates, for the NATS client: the
     *                                     connection's lifecycle at info level, its errors at error level, among them a
     *                                     reconnect that gave up and discarded the acknowledgements buffered meanwhile.
     *                                     The transport also warns through it, once, when Symfony's retry strategy is
     *                                     on in nats mode (max_retries > 0 with retry_handler=nats).
     *                                     The bundle passes the application's `logger` service when there is one.
     * @param list<string> $transportsWithAFailureTransport The names, in framework.messenger.transports, of the
     *                                     transports that have a failure transport, their own or the global one.
     *                                     The bundle fills it in. give_up_via 'nak' is refused for those.
     */
    public function __construct(private readonly ?LoggerInterface $logger = null, private readonly array $transportsWithAFailureTransport = [])
    {
    }

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
    public function createTransport(#[\SensitiveParameter] string $dsn, #[\SensitiveParameter] array $options, SerializerInterface $serializer): TransportInterface
    {
        $transport = new NatsTransport($dsn, $options, $serializer, $this->logger);
        $this->assertNoFailureTransportWhenTheMessageStaysInTheStream($dsn, $options);

        return $transport;
    }

    /**
     * Refuses give_up_via 'nak' for a transport that has a failure transport.
     *
     * With give_up_via 'nak' a message Symfony gives up on stays in the stream. Symfony's failure transport listener
     * sends a copy of every such message to the transport's failure transport, and a transport cannot stop a
     * listener, so the message would exist twice. FrameworkBundle passes each transport's name in its options, and
     * the bundle passes the names of the transports with a failure transport, so the two can be compared here,
     * where the DSN is resolved, an environment variable included.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException When the transport has a failure transport and give_up_via is 'nak'.
     */
    private function assertNoFailureTransportWhenTheMessageStaysInTheStream(#[\SensitiveParameter] string $dsn, #[\SensitiveParameter] array $options): void
    {
        $name = $options['transport_name'] ?? null;
        if (!is_string($name) || !in_array($name, $this->transportsWithAFailureTransport, true)) {
            return;
        }

        if ((new NatsTransportConfigurationBuilder())->build($dsn, $options)->giveUpVia() !== GiveUpVia::NAK) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "The transport \"%s\" has give_up_via 'nak' and a failure transport: a message Symfony gives up on would stay in"
            . ' the stream and be copied to the failure transport as well. Remove the failure transport from this'
            . ' transport (a global framework.messenger.failure_transport applies to every transport), or use'
            . " give_up_via 'term'.",
            $name,
        ));
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
    public function supports(#[\SensitiveParameter] string $dsn, #[\SensitiveParameter] array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME) || str_starts_with($dsn, self::TLS_SCHEME);
    }
}