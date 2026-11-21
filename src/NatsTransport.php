<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger;

use Amp\Future;
use Amp\TimeoutCancellation;
use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Core\NatsHeaders;
use IDCT\NATS\Core\NatsMessage;
use IDCT\NATS\Exception\ConnectionException;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\Exception\SubscriptionLimitException;
use IDCT\NATS\Exception\TimeoutException;
use IDCT\NATS\Exception\UnsupportedFeatureException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
use IDCT\NATS\JetStream\Enum\AckPolicy;
use IDCT\NATS\JetStream\Enum\DeliverPolicy;
use IDCT\NATS\JetStream\Enum\NoRespondersCause;
use IDCT\NATS\JetStream\Enum\ReplayPolicy;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\JsMessageMetadata;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NATS\JetStream\Schedule;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Options\RetryHandler;
use IDCT\NatsMessenger\Options\RetryVia;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

use function Amp\delay;

/**
 * Symfony Messenger transport for NATS JetStream.
 *
 * Implements the full transport lifecycle: sending envelopes to a JetStream subject,
 * pulling batches via a durable pull consumer, acknowledging or rejecting messages,
 * and provisioning the underlying stream and consumer via {@see setup()}.
 *
 * Connection to NATS is established lazily on the first transport operation.
 * All JetStream interactions use explicit ACK policy with pull-based consumers.
 *
 * @see NatsTransportFactory     Creates instances of this transport from Symfony DSN configuration.
 * @see NatsTransportConfiguration  Holds the resolved, immutable runtime settings.
 */
class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface, KeepaliveReceiverInterface, CloseableTransportInterface
{
    /** Conversion factor for stream max_age (seconds → nanoseconds as required by JetStream API). */
    private const SECONDS_TO_NANOSECONDS = 1_000_000_000;

    /**
     * How the client's JetStreamException starts when a pull got no answer before the client's own deadline,
     * max_batch_timeout plus a second. A server that is there ends every pull before then, with status 408 when
     * it found no messages, so unlike other JetStream errors this one does not prove that the server answered.
     */
    private const UNANSWERED_PULL = 'No messages received within timeout';

    /** Symfony serializer used to encode/decode envelopes to/from wire payloads. */
    protected SerializerInterface $serializer;

    /** Low-level NATS client managing the TCP/TLS connection and request/publish calls. */
    protected NatsClient $client;

    /** Lazily initialized JetStream context; null until {@see connectIfNeeded()} runs. */
    protected ?JetStreamContext $jetStream = null;

    /** JetStream subject name that messages are published to and consumed from. */
    protected string $topic;

    /** JetStream stream name that backs the transport subject. */
    protected string $streamName;

    /** Resolved immutable transport configuration (consumer, batching, timeouts, etc.). */
    protected NatsTransportConfiguration $configuration;

    /**
     * Whether the setup() now running warns about a nats-mode stream that loses what max_deliver stops
     * ({@see warnIfNatsModeLosesWhatMaxDeliverStops()}): not when auto_setup runs it for a send.
     */
    private bool $warnAboutWhatMaxDeliverStops = true;

    /** Tracks whether the one-shot {@see autoSetupIfEnabled()} provisioning has already run this instance. */
    private bool $autoSetupDone = false;

    /**
     * The client's reconnect count when {@see autoSetupIfEnabled()} last verified provisioning, with the client
     * owning recovery; null otherwise, and the flag above is then trusted on its own ({@see clientReconnectedSinceSetup()}).
     */
    private ?int $reconnectsAtSetup = null;

    /**
     * JetStream err_code of "can not move and scale a stream in a single update" (JSStreamMoveAndScaleErr),
     * see {@see describeSetupRejection()}.
     */
    private const JS_ERR_STREAM_MOVE_AND_SCALE = 10123;

    /**
     * JetStream err_code of "JetStream system temporarily unavailable" (JSClusterNotAvailErr), which setup() waits
     * out for its consumer requests, see {@see whileTheClusterSettles()}.
     */
    private const JS_ERR_CLUSTER_NOT_AVAILABLE = 10008;

    /**
     * JetStream err_code of "stream not found" (JSStreamNotFoundErr). A consumer request of setup() can get it for a
     * moment right after the stream update that changed the replica count, see {@see whileTheClusterSettles()}.
     */
    private const JS_ERR_STREAM_NOT_FOUND = 10059;

    /** How long {@see whileTheClusterSettles()} sends a consumer request again, in seconds. */
    private const CLUSTER_SETTLE_SECONDS = 5.0;

    /** The pause before each of those requests, in seconds. */
    private const CLUSTER_SETTLE_PAUSE = 0.1;

    /**
     * When an operation last used the connection, in monotonic seconds; null while the transport has none.
     * Tells how long the connection sat idle ({@see connectionUsable()}).
     */
    private ?float $lastUsedAt = null;

    /**
     * Whether an operation failed on the connection since it was last known to work, so that the next one
     * checks it first ({@see awaitOnConnection()}).
     */
    private bool $checkBeforeNextUse = false;

    /**
     * The longest delay a NAK carries, in milliseconds: the client sends it in nanoseconds, as a PHP integer.
     * About 292 years, so it only keeps an absurd delay from overflowing.
     */
    private const MAX_NAK_DELAY_MS = 9_223_372_036_854;

    /** The first NATS server release with message schedules, which scheduled_messages needs. */
    private const SCHEDULES_SINCE = '2.12';

    /**
     * The delivery token (reply subject) of each received message whose retry copy {@see send()} left out, in nats
     * mode or with retry_via=nak, by message, until the worker settles that delivery
     * ({@see natsRedeliversTheRetryOf()}). With it, the delay Symfony's retry strategy computed for the retry in
     * milliseconds with retry_via=nak, or null in nats mode, where nak_delay applies. Weak, so a message that is
     * never settled leaves nothing behind.
     *
     * @var \WeakMap<object, array{id: string, delayMs: int|null}>|null
     */
    private ?\WeakMap $retriesNatsRedelivers = null;

    /** The logger this transport was given, which the NATS client got too; null without one. */
    private ?LoggerInterface $logger;

    /**
     * Whether this transport has logged that Symfony's retry strategy is on while it is in nats mode
     * ({@see warnThatSymfonysRetryStrategyIsOn()}), which it does once.
     */
    private bool $symfonysRetryStrategyReported = false;

    /**
     * How long the max_deliver of the consumer, as the server last reported it, is trusted before
     * {@see consumersMaxDeliver()} asks for it again, in seconds.
     */
    private const CONSUMER_MAX_DELIVER_TTL = 60.0;

    /**
     * The max_deliver of this transport's consumer as the server last reported it (null for no limit), with the
     * monotonic time {@see consumersMaxDeliver()} read it at. Null while it was never read.
     *
     * @var array{value: int|null, at: float}|null
     */
    private ?array $consumersMaxDeliver = null;

    /**
     * Whether asking for the consumer failed on the current connection, which is then not asked again until the
     * connection is replaced or closed ({@see consumersMaxDeliver()}).
     */
    private bool $consumerUnreadableOnThisConnection = false;

    /** Whether {@see consumersMaxDeliver()} has logged that the consumer could not be read, which it does once. */
    private bool $consumerUnreadableReported = false;

    /**
     * Whether {@see consumersMaxDeliver()} has logged that the consumer's max_deliver is not the option's, which it
     * does once.
     */
    private bool $maxDeliverMismatchReported = false;

    /**
     * Whether the stream was found to keep the delayed messages scheduled_messages publishes, or could not be checked
     * ({@see assertTheStreamKeepsDelayedMessages()}): then neither setup() nor a pull checks it again.
     */
    private bool $delayedMessagesChecked = false;

    /**
     * Whether {@see warnIfARetryLosesItsDelay()} has logged, which it does once.
     */
    private bool $retryDelayDroppedReported = false;

    /**
     * Creates a transport instance from DSN/options and optional serializer override.
     *
     * Parses the DSN and options via {@see NatsTransportConfigurationBuilder}, sets up
     * the serializer (defaults to {@see IgbinarySerializer} if the extension is available),
     * and stores the resolved configuration. No connection is made at construction time.
     *
     * @param string                 $dsn        NATS JetStream DSN (e.g. nats-jetstream://host:4222/stream/topic)
     * @param array<string, mixed>   $options    Transport option overrides (take precedence over DSN query params)
     * @param SerializerInterface|null $serializer Custom serializer; when null, defaults to igbinary
     * @param LoggerInterface|null   $logger     The NATS client's logger: the connection's lifecycle at info level,
     *                                           its errors at error level, among them a reconnect that gave up and
     *                                           discarded the acknowledgements buffered meanwhile. The transport
     *                                           keeps it as well, for a warning in nats mode when Symfony's retry
     *                                           strategy is on ({@see warnThatSymfonysRetryStrategyIsOn()}). The
     *                                           bundle hands the application's logger to {@see NatsTransportFactory}.
     */
    public function __construct(#[\SensitiveParameter] string $dsn, #[\SensitiveParameter] array $options, ?SerializerInterface $serializer = null, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger;

        if ($serializer !== null) {
            $this->serializer = $serializer;
        } elseif ($this->isExtensionLoaded('igbinary')) {
            $this->serializer = new IgbinarySerializer();
        } else {
            // PhpSerializer uses native unserialize(), which is the same untrusted-deserialization
            // (object injection) sink as igbinary. Emit a warning - not a quiet notice - so the
            // fallback is not silently relied upon in production. See the README security section.
            trigger_error(
                'The igbinary extension is not installed. Falling back to Symfony\\Component\\Messenger\\Transport\\Serialization\\PhpSerializer, which uses native unserialize() and carries the same untrusted-deserialization (object injection) risk as igbinary. Install ext-igbinary, or explicitly configure a safe serializer - especially when consuming from untrusted NATS subjects.',
                E_USER_WARNING,
            );
            $this->serializer = new PhpSerializer();
        }

        $configuration = (new NatsTransportConfigurationBuilder())->build($dsn, $options, $logger);
        $this->configuration = $configuration;
        $this->topic = $configuration->topic;
        $this->streamName = $configuration->streamName;
        $this->client = $configuration->client;
    }

    /**
     * Whether the installed Symfony Messenger passes a message that could not be decoded on as a message, as 8.1 and
     * later do: MessageDecodingFailedException::wrap() exists from 8.1 on, and the worker then handles the failure.
     * Exposed for tests, which run on both sides of 8.1.
     */
    protected function messengerPassesOnDecodeFailures(): bool
    {
        // Asked through reflection: the analysis knows only the release installed with it.
        return (new \ReflectionClass(MessageDecodingFailedException::class))->hasMethod('wrap');
    }

    /**
     * Before Symfony Messenger 8.1, as its own transports do (Doctrine, Redis, AMQP): a message that cannot be decoded
     * is settled for good, a TERM here, since a redelivery cannot decode it either, and the decode error is thrown,
     * which stops the worker. In nats mode up to 5.9.0 it was NAKed instead, and NATS brought it to every new worker,
     * which stopped again, for ever without max_deliver. A TERM that fails, as on a connection that dropped, never
     * replaces the decode error: that is the cause an operator needs, and the message comes back after ack_wait.
     */
    private function termAndThrow(string $replyTo, MessageDecodingFailedException $failure): never
    {
        try {
            $this->sendTerm($replyTo);
        } catch (\Throwable) {
            // The decode error below is the failure to report.
        }

        throw $failure;
    }

    /**
     * MessageDecodingFailedException::wrap() of Symfony Messenger 8.1: an envelope whose message is the failure, with
     * what was received. Made through reflection, as wrap() and the constructor's encoded envelope exist only from 8.1
     * on, and the static analysis knows only the release installed with it, Messenger 7.4 on CI's lowest leg.
     *
     * @param array<string, mixed> $encoded
     */
    private static function decodingFailure(array $encoded, MessageDecodingFailedException $failure): Envelope
    {
        return new Envelope((new \ReflectionClass(MessageDecodingFailedException::class))->newInstance($failure->getMessage(), $failure->getCode(), $failure, $encoded));
    }

    /**
     * Exposed for testability around extension checks.
     */
    protected function isExtensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /**
     * Sends a messenger envelope to JetStream.
     *
     * Assigns a UUID v4 transport message ID, serializes the envelope, and publishes
     * the payload (with any envelope headers) to the configured subject via
     * {@see JetStreamContext::publish()}, which validates the JetStream publish
     * acknowledgement and fails closed on an error or malformed response.
     *
     * When scheduled messages are enabled and the envelope carries a {@see DelayStamp},
     * the message is published to a unique delayed subject with NATS schedule headers,
     * causing JetStream to hold it until the scheduled time before delivering it to
     * the original topic.
     *
     * With retry_handler=nats, the copy Symfony's retry sends of a message this transport received is not
     * published ({@see isRetryNatsRedeliversItself()}): the delivery it belongs to is remembered instead, so that
     * the worker's ack() of it NAKs it ({@see natsRedeliversTheRetryOf()}). The first such copy is logged as a
     * warning that Symfony's retry strategy is on ({@see warnThatSymfonysRetryStrategyIsOn()}). With retry_via=nak
     * the copy is left out the same way when a NAK can carry the retry, and the delay Symfony computed for it is
     * remembered with the delivery, for the NAK to carry; nothing is logged, since the strategy is on by intent.
     *
     * @throws RuntimeException     If serialization fails and the envelope carries an ErrorDetailsStamp.
     * @throws \Throwable           The original serializer exception when serialization fails and the
     *                              envelope carries no ErrorDetailsStamp (re-thrown unchanged).
     * @throws JetStreamException   If JetStream rejects the publish or returns an invalid ack.
     * @throws ConnectionException  If the connection cannot be opened or is lost before the publish completes.
     * @throws TimeoutException     If the publish acknowledgement does not arrive within request_timeout. The
     *                              server may still have stored the message.
     */
    public function send(Envelope $envelope): Envelope
    {
        if ($this->isRetryNatsRedeliversItself($envelope)) {
            // With retry_via=nak Symfony's retry strategy is on by intent: its delay is what the NAK carries.
            $nakRetry = $this->configuration->retryVia() === RetryVia::NAK;
            if (!$nakRetry) {
                $this->warnThatSymfonysRetryStrategyIsOn();
            }

            $delivery = $envelope->last(TransportMessageIdStamp::class);
            if ($delivery !== null) {
                $this->retriesNatsRedelivers ??= new \WeakMap();
                $this->retriesNatsRedelivers[$envelope->getMessage()] = [
                    'id' => TypeCoercion::stringValue($delivery->getId()),
                    'delayMs' => $nakRetry ? $envelope->last(DelayStamp::class)?->getDelay() ?? 0 : null,
                ];
            }

            return $envelope;
        }

        $this->autoSetupIfEnabled(toSend: true);

        // Read before the copy gets its own id: with retry_via=nak a retry is published here for want of a token.
        $hadADeliveryToken = $envelope->last(TransportMessageIdStamp::class) !== null;
        $uuid = (string) Uuid::v4();
        $envelope = $envelope->with(new TransportMessageIdStamp($uuid));

        // Added before the envelope is encoded, so that the id travels with the message (#53).
        $deduplicationStamp = $envelope->last(DeduplicationIdStamp::class);
        if ($deduplicationStamp === null && $this->configuration->isDeduplicationEnabled()) {
            $deduplicationStamp = new DeduplicationIdStamp($uuid);
            $envelope = $envelope->with($deduplicationStamp);
        }

        try {
            $encodedMessage = $this->serializer->encode($envelope);
        } catch (\Throwable $serializationError) {
            $errorStamp = $envelope->last(ErrorDetailsStamp::class);
            if ($errorStamp !== null) {
                throw new RuntimeException($errorStamp->getExceptionMessage(), 0, $serializationError);
            }

            throw $serializationError;
        }

        $payload = TypeCoercion::stringValue($encodedMessage['body']);
        $headers = is_array($encodedMessage['headers'] ?? null) ? $encodedMessage['headers'] : [];
        $topic = $this->topic;

        $delayMs = $envelope->last(DelayStamp::class)?->getDelay();
        if ($delayMs > 0 && !$this->configuration->isScheduledMessagesEnabled()) {
            $this->warnIfARetryLosesItsDelay($envelope, $delayMs, $hadADeliveryToken);
        }

        if ($delayMs > 0 && $this->configuration->isScheduledMessagesEnabled()) {
            // The server's version is known once connected, and an older server would store the message for good.
            $this->connectIfNeeded();
            $unsupported = $this->schedulesUnsupportedByTheServer();
            if ($unsupported !== null) {
                throw new TransportException($this->describeUnsupportedFeature($unsupported) . ' The delayed message was not sent: this server would store it and never deliver it.', 0, $unsupported);
            }

            $deliverAt = $this->deliveryTime($delayMs);
            // The @at schedule expression has whole-second resolution and truncates any sub-second
            // component. Round up to the next whole second when one is present so a delayed message is
            // never delivered before the requested delay elapses - truncating down could otherwise fire
            // it up to ~1s early, and would make a sub-second delay fire immediately.
            if ((int) $deliverAt->format('u') !== 0) {
                $deliverAt = $deliverAt->modify('+1 second');
            }
            $headers['Nats-Schedule'] = Schedule::at($deliverAt);
            $headers['Nats-Schedule-Target'] = $this->topic;
            $topic = $this->topic . '.delayed.' . $uuid;
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[$name] = TypeCoercion::stringValue($value);
        }

        // A single JetStream publish path for both plain and header-carrying (incl. scheduled)
        // messages. JetStreamContext::publish() retries transient 503 "no responders" and parses
        // the PubAck, throwing JetStreamException on an empty/malformed reply or a reported error -
        // so the publish fails closed instead of silently accepting an invalid acknowledgement.
        $messageId = $deduplicationStamp === null ? null : $this->natsMessageId($deduplicationStamp, $envelope);
        try {
            $this->awaitOnConnection(fn (): Future => $this->jetStream()->publish($topic, $payload, $normalizedHeaders, msgId: $messageId));
        } catch (JetStreamException $reply) {
            if (!$this->reprovisionedForThePublishAfterReconnect($reply)) {
                throw $reply;
            }

            // The same frame once more: the same subject (the scheduled one of a delayed message), payload, headers
            // and Nats-Msg-Id. A second pass through send() would mint another id and, for a delayed message,
            // another schedule.
            $this->awaitOnConnection(fn (): Future => $this->jetStream()->publish($topic, $payload, $normalizedHeaders, msgId: $messageId));
        }

        return $envelope;
    }

    /**
     * Logs, the first time it happens on this transport object, that Symfony's retry of a received message is published
     * at once, without the delay its retry strategy computed: a message published on JetStream waits only as a
     * schedule, with scheduled_messages. With retry_via: copy that is every retry: the retries of a message that keeps
     * failing all run within milliseconds, whatever the strategy's delay and multiplier. With retry_via: nak it is
     * a retry no NAK can carry, which goes out as a copy: the one of the last delivery the consumer allows, or one that
     * carries no delivery token, which the message names apart, since raising max_deliver helps only the first.
     *
     * Logged once per transport object, so once per worker, through the logger the transport was given. A failure
     * transport's copy carries no delay and an application's own delayed message no retry count, so neither is
     * concerned.
     */
    private function warnIfARetryLosesItsDelay(Envelope $envelope, int $delayMs, bool $hadADeliveryToken): void
    {
        if ($this->retryDelayDroppedReported || $envelope->last(ReceivedStamp::class) === null || RedeliveryStamp::getRetryCountFromEnvelope($envelope) <= 0) {
            return;
        }

        $this->retryDelayDroppedReported = true;
        if ($this->configuration->retryVia() !== RetryVia::NAK) {
            $this->logger?->warning(sprintf(
                'retry_via=copy on stream "%s", subject "%s": Symfony\'s retry was published at once, without its delay of'
                . ' %d ms: a copy waits only with scheduled_messages. Set retry_via: nak to have NATS wait, or'
                . ' scheduled_messages: true (NATS 2.12+, not on an interest stream).',
                $this->streamName,
                $this->topic,
                $delayMs,
            ));

            return;
        }

        $this->logger?->warning($hadADeliveryToken
            ? sprintf(
                'retry_via=nak on stream "%s", subject "%s": the retry of the last delivery the consumer allows was published'
                . ' as a copy at once, without its delay of %d ms. NATS redelivers nothing after that delivery, so no NAK'
                . ' can carry this retry. scheduled_messages: true (NATS 2.12+, not on an interest stream) makes the copy'
                . ' wait, and a max_deliver above the retries Symfony makes keeps it from being needed.',
                $this->streamName,
                $this->topic,
                $delayMs,
            )
            : sprintf(
                'retry_via=nak on stream "%s", subject "%s": Symfony\'s retry was published as a copy at once, without its'
                . ' delay of %d ms, as it carried no delivery token for a NAK to settle: something between the worker and'
                . ' the transport removed its TransportMessageIdStamp. scheduled_messages: true (NATS 2.12+, not on an'
                . ' interest stream) makes such a copy wait.',
                $this->streamName,
                $this->topic,
                $delayMs,
            ));
    }

    /**
     * When a message sent now with a delay of $delayMs milliseconds is due. Exposed for testability: send() rounds
     * a time with a fraction of a second up to the next whole second, and a test needs a time without one.
     */
    protected function deliveryTime(int $delayMs): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('+%d milliseconds', $delayMs));
    }

    /**
     * Whether a publish the server had no responder for gets one more try, after setup(), because the client
     * reconnected on its own meanwhile.
     *
     * {@see autoSetupIfEnabled()} verifies provisioning before the publish, but a send begun while the client was
     * recovering passes it with the old reconnect count: the recovery completes inside the publish's wait, the
     * publish lands on the recovered connection, and when the stream went with the server (a memory stream over a
     * restart, a failover to a server that does not have it) it fails with the client's 503 "No JetStream
     * responder", after the client's own three attempts. So, with auto_setup on and the count moved since the last
     * setup(), setup() runs once and the frame is published once more; a second failure propagates. Bounded to that
     * and to this reply: nothing answered the first publish, so the message was not stored, and the second cannot
     * duplicate it, with or without a Nats-Msg-Id. Any other failure propagates as it always did.
     */
    private function reprovisionedForThePublishAfterReconnect(JetStreamException $reply): bool
    {
        if ($reply->getCode() !== 503 || !$this->configuration->isAutoSetupEnabled() || !$this->clientReconnectedSinceSetup()) {
            return false;
        }

        // autoSetupIfEnabled() provisions again on its own here: the count moved since the last setup().
        $this->autoSetupIfEnabled(toSend: true);

        return true;
    }

    /**
     * The Nats-Msg-Id JetStream deduplicates a publish by.
     *
     * The envelope's deduplication id, then this transport's subject, then Symfony's retry count, and on the
     * copy for a failure transport, which Symfony sends with a retry count of 0, a marker with the transport
     * the message failed on. JetStream deduplicates per stream, whatever the subject, and Symfony hands each
     * transport a message is routed to the envelope the one before returned, stamp included: the subject keeps
     * apart the copies of transports that share a stream, the retry count keeps each retry a message of its
     * own, and the marker keeps the failure copies apart from the original and from each other. A copy sent
     * again, such as the retry of a message NATS redelivered because the worker stopped after a retry send
     * that timed out, gets the same id as before and is dropped.
     */
    private function natsMessageId(DeduplicationIdStamp $stamp, Envelope $envelope): string
    {
        $id = sprintf('%s:%s:%d', $stamp->id, $this->topic, RedeliveryStamp::getRetryCountFromEnvelope($envelope));
        $failure = $envelope->last(SentToFailureTransportStamp::class);

        return $failure === null ? $id : sprintf('%s:failed:%s', $id, $failure->getOriginalReceiverName());
    }

    /**
     * Whether the envelope is the copy Symfony's retry sends of a message this transport received, while
     * NATS handles redelivery (retry_handler=nats), or one that a NAK carries instead (retry_via=nak).
     *
     * Symfony's retry listener runs for every transport with a retry strategy, and FrameworkBundle gives each
     * one a strategy by default. It re-sends the failed message with a retry count, and the worker then settles
     * the original, which in nats mode is NAKed so NATS redelivers it. Publishing the copy as well
     * retried the same failure twice, and every copy did the same again, multiplying the deliveries: one
     * message that kept failing ran its handler 120 times with max_deliver 3 (#47). So in nats mode the copy
     * is not published, which means Symfony's retry strategy (max_retries, delay, multiplier) is ignored and
     * NATS redelivers alone, under nak_delay, backoff and max_deliver.
     *
     * The worker settles the original with reject() up to Symfony Messenger 7.4.21 and 8.1.9, and with ack()
     * from 7.4.22 and 8.1.10 on, since the retry copy was sent (symfony/symfony#67038). An ACK would drop the
     * message for good, so ack() NAKs a delivery whose copy was left out ({@see natsRedeliversTheRetryOf()},
     * #90).
     *
     * With retry_via=nak, Symfony's retry strategy decides and its copy is left out as well: the delivery that
     * failed is NAKed with the copy's delay, and NATS redelivers the original. Only a retry a NAK can carry is
     * left out then: the copy has to carry its delivery token, and the delivery must not be the last one
     * max_deliver allows, after which NATS redelivers nothing ({@see isTheLastDelivery()}). Any other copy is
     * published, as with retry_via=copy, so a retry Symfony decided on always happens.
     *
     * A copy for the failure transport carries a retry count of 0 and is still sent, as is anything that was
     * not received in this process.
     */
    private function isRetryNatsRedeliversItself(Envelope $envelope): bool
    {
        if ($envelope->last(ReceivedStamp::class) === null || RedeliveryStamp::getRetryCountFromEnvelope($envelope) <= 0) {
            return false;
        }

        if ($this->configuration->retryHandler() === RetryHandler::NATS) {
            return true;
        }

        if ($this->configuration->retryVia() !== RetryVia::NAK) {
            return false;
        }

        $delivery = $envelope->last(TransportMessageIdStamp::class);

        return $delivery !== null && !$this->isTheLastDelivery(TypeCoercion::stringValue($delivery->getId()));
    }

    /**
     * Whether this is the last delivery the consumer's max_deliver allows: NATS does not redeliver the message after
     * it, whatever the NAK says, and publishes the MAX_DELIVERIES advisory instead. The deliveries so far come from
     * the delivery's reply subject, and the limit is the one the server enforces ({@see consumersMaxDeliver()}).
     * False without a limit, and for a token that is no JetStream reply subject.
     *
     * Up to 5.9.0 the limit was the max_deliver option. A consumer whose limit was not the option's lost a retry: the
     * option raised (or left unset, the default) without running setup, or another configuration's setup on the
     * same consumer. With the option at 5 on a consumer limited to 3, or unset on one limited to 3, and max_retries 4,
     * the retry of the third delivery was NAKed, NATS redelivered nothing, and the message was gone: 3 handler runs,
     * no failure-transport copy (measured on nats-server 2.15.2).
     */
    private function isTheLastDelivery(string $id): bool
    {
        $delivered = JsMessageMetadata::fromMessage($this->buildAckMessage($id))?->numDelivered;
        if ($delivered === null) {
            return false;
        }

        $maxDeliver = $this->consumersMaxDeliver();

        return $maxDeliver !== null && $delivered >= $maxDeliver;
    }

    /**
     * The max_deliver the server enforces for this transport's consumer, null for no limit.
     *
     * A consumer info request gives it, made at the first retry that needs it and again once the answer is older
     * than {@see CONSUMER_MAX_DELIVER_TTL}: one request a minute at most, made only while deliveries fail with
     * retry_via=nak. So a change of the consumer's limit is seen within a minute. A consumer whose limit is not the
     * max_deliver option's is followed, and logged once, since that limit is the one NATS applies: the option takes
     * effect only once setup writes it to the consumer.
     *
     * setup()'s own answer is not taken: another configuration's setup on the same consumer can change its limit right
     * after, as happens when two processes start, and the first retry usually comes later than that.
     *
     * A consumer that cannot be read, such as for a user who may not ask for consumer info, leaves the last value read,
     * or the option when there is none, and is logged once; it is not asked again until the connection is replaced or
     * closed. From client 2.30.0 on, the server's refusal of that request ends it at once; before, it took the
     * request_timeout.
     */
    private function consumersMaxDeliver(): ?int
    {
        $known = $this->consumersMaxDeliver;
        $stale = $known === null || $this->monotonicSeconds() - $known['at'] >= self::CONSUMER_MAX_DELIVER_TTL;
        if ($stale && !$this->consumerUnreadableOnThisConnection) {
            try {
                $this->rememberConsumersMaxDeliver(
                    $this->awaitOnConnection(fn (): Future => $this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer())),
                );
                $known = $this->consumersMaxDeliver;
            } catch (\Throwable $failure) {
                $this->consumerUnreadableOnThisConnection = true;
                $this->warnThatTheConsumerCouldNotBeRead($failure, $known);
            }
        }

        $option = $this->configuration->maxDeliver();
        if ($known === null) {
            return $option;
        }

        if ($known['value'] !== $option) {
            $this->warnThatTheConsumersMaxDeliverIsNotTheOptions($known['value'], $option);
        }

        return $known['value'];
    }

    /**
     * Keeps the max_deliver a consumer info reports, as the one the server enforces now. NATS reports -1 for a
     * consumer without a limit.
     */
    private function rememberConsumersMaxDeliver(ConsumerInfo $consumerInfo): void
    {
        $config = self::configOf($consumerInfo);
        $maxDeliver = $config['max_deliver'] ?? null;

        $this->consumersMaxDeliver = [
            'value' => is_int($maxDeliver) && $maxDeliver > 0 ? $maxDeliver : null,
            'at' => $this->monotonicSeconds(),
        ];
    }

    /**
     * Logs, once per transport object, that the consumer could not be read for its max_deliver
     * ({@see consumersMaxDeliver()}).
     *
     * @param array{value: int|null, at: float}|null $known
     */
    private function warnThatTheConsumerCouldNotBeRead(\Throwable $failure, ?array $known): void
    {
        if ($this->consumerUnreadableReported) {
            return;
        }

        $this->consumerUnreadableReported = true;
        $this->logger?->warning(sprintf(
            'retry_via=nak on stream "%s": consumer "%s" could not be read for its max_deliver (%s), so %s tells which'
            . ' delivery is the last one. Let this user ask for consumer info ($JS.API.CONSUMER.INFO.%s.%s).',
            $this->streamName,
            $this->configuration->consumer(),
            $failure->getMessage(),
            $known !== null
                ? 'the value read before (' . self::describeMaxDeliver($known['value']) . ')'
                : 'the max_deliver option (' . self::describeMaxDeliver($this->configuration->maxDeliver()) . ')',
            $this->streamName,
            $this->configuration->consumer(),
        ));
    }

    /**
     * Logs, once per transport object, that the consumer's max_deliver is not the option's ({@see consumersMaxDeliver()}).
     */
    private function warnThatTheConsumersMaxDeliverIsNotTheOptions(?int $consumers, ?int $option): void
    {
        if ($this->maxDeliverMismatchReported) {
            return;
        }

        $this->maxDeliverMismatchReported = true;
        $this->logger?->warning(sprintf(
            'retry_via=nak on stream "%s": consumer "%s" has max_deliver %s and the max_deliver option is %s. The'
            . ' transport follows the consumer, whose limit NATS applies. Run messenger:setup-transports to give the'
            . ' consumer the option\'s value.',
            $this->streamName,
            $this->configuration->consumer(),
            self::describeMaxDeliver($consumers),
            self::describeMaxDeliver($option),
        ));
    }

    private static function describeMaxDeliver(?int $maxDeliver): string
    {
        return $maxDeliver === null ? 'unset (no limit)' : (string) $maxDeliver;
    }

    /**
     * Logs a warning, the first time {@see send()} leaves out the copy Symfony's retry sends, that Symfony's retry
     * strategy is on for this transport in nats mode, where it has no effect (#94).
     *
     * FrameworkBundle gives every transport max_retries: 3, and the README asks for max_retries: 0 in nats mode, in
     * a paragraph that is easy to miss. With retries on, Symfony logs every failure as a retry it never makes
     * ("Sending for retry #1"), dispatches WorkerMessageRetriedEvent for it, and never treats the message as failed
     * for good, so a failure transport never receives it; up to 5.7.1, on Symfony Messenger 7.4.22 / 8.1.10 and
     * later, the message was lost after its first failure (#90).
     *
     * A strategy that does not retry (max_retries: 0, or one of its own that says no) sends no copy, so the first
     * copy left out proves that retries are on: no configuration is read and nothing is guessed, whether
     * retry_handler comes from the transport's options or from a DSN in an environment variable. Once per
     * transport object, so a worker logs it once rather than for every failure, through the logger the transport
     * was given; without one nothing is logged.
     */
    private function warnThatSymfonysRetryStrategyIsOn(): void
    {
        if ($this->symfonysRetryStrategyReported) {
            return;
        }

        $this->symfonysRetryStrategyReported = true;
        $this->logger?->warning(sprintf(
            'retry_handler=nats on stream "%s", subject "%s": Symfony\'s retry strategy retried a message that NATS'
            . ' redelivers itself. Set max_retries: 0 on this transport (Symfony still retries a'
            . ' RecoverableExceptionInterface), or have NATS carry out Symfony\'s retries with retry_handler: symfony'
            . ' and retry_via: nak (README, "Retry Handler Behavior").',
            $this->streamName,
            $this->topic,
        ));
    }

    /**
     * Pulls and decodes a batch of envelopes from JetStream.
     *
     * Fetches up to {@see NatsTransportConfiguration::batching()} messages with the
     * configured timeout. A pull that found no messages (JetStream status 408, or 404) is an
     * empty result, and so is one the server did not answer, though the next operation then checks
     * the connection first, unless ping_after_idle is 0. A missing consumer or stream does not report
     * 404 but 503, or 409 when the consumer is deleted mid-pull, and without auto_setup that
     * JetStreamException propagates.
     * A message without a reply (ack) subject is skipped (it can be neither acknowledged nor
     * rejected); a message with an empty payload is TERMed so JetStream stops redelivering it,
     * since it can never decode into an envelope. A header whose name is a decimal integer, such as
     * `1`, is not passed to the serializer, whose decode() takes string names. A message the serializer
     * cannot decode (a MessageDecodingFailedException) is handled as Symfony's own transports handle it:
     * from Symfony Messenger 8.1 on it goes on as the decoding failure, which the worker handles and
     * fails; before 8.1 it is TERMed and the decode error thrown ({@see termAndThrow()}). Any other
     * exception of the serializer propagates, and the message is left for redelivery.
     *
     * With auto_setup enabled, a 404, 409 or 503 (see {@see recoverFromFetchFailure()} for why
     * all three) first triggers one re-provisioning attempt and a second pull. A 409 with which the
     * server ended the pull itself, "Server Shutdown" or "Leadership Change", is an empty batch
     * instead, in either mode ({@see pullEndedByTheServer()}). So is a 503 from a server that is going
     * away, in a graceful stop or lame duck mode, which the client's diagnosis of the 503 tells from a
     * missing consumer ({@see pullMetAServerGoingAway()}, #106).
     *
     * A pull the server refuses because the connection is at its subscription limit fails with a
     * TransportException that names the stream, the consumer and the limit ({@see fetchBatchMessages()}).
     *
     * @return iterable<Envelope>
     *
     * @throws TransportException If the pull fails because the connection is at its subscription limit.
     */
    public function get(): iterable
    {
        $this->autoSetupIfEnabled();
        // A worker is the process that sends Symfony's delayed retries, so it checks before its first pull.
        $this->assertTheStreamKeepsDelayedMessages(null);

        try {
            $messages = $this->fetchBatchMessages();
        } catch (JetStreamException $exception) {
            $messages = $this->recoverFromFetchFailure($exception);

            if ($messages === null) {
                return [];
            }
        }

        foreach ($messages as $message) {
            // A delivered message without a reply (ack) subject can neither be acknowledged
            // nor rejected, so an envelope built from it could never be completed by the
            // worker. Skip it rather than yield an envelope with an unusable transport id.
            // (Pull-delivered JetStream data messages always carry an ack subject.)
            $replyTo = $message->replyTo;
            if ($replyTo === null || $replyTo === '') {
                continue;
            }

            // An empty payload can never decode into a Messenger envelope. Skipping it without
            // acknowledging would leave it unacked, so JetStream would redeliver it every ack_wait
            // forever (a poison loop). TERM it instead - redelivery cannot fix an empty body - so
            // it is dropped regardless of the configured retry handler.
            if ($message->payload === '') {
                $this->sendTerm($replyTo);

                continue;
            }

            // fromWireBlock() reads a message without headers (null, or an empty block) as no headers.
            $headers = self::stringNamedHeaders(NatsHeaders::fromWireBlock($message->rawHeaders));

            $encoded = ['body' => $message->payload, 'headers' => $headers];
            try {
                $decoded = $this->serializer->decode($encoded);
            } catch (MessageDecodingFailedException $e) {
                if (!$this->messengerPassesOnDecodeFailures()) {
                    $this->termAndThrow($replyTo, $e);
                }

                // From Symfony Messenger 8.1 on, as its own transports do: the failure goes on as a message, which
                // the worker handles and fails, so that the retry strategy and the failure transport deal with it (in
                // nats mode its settling is a TERM, see isAMessageNatsModeCannotDecode()).
                $decoded = self::decodingFailure($encoded, $e);
            }

            yield $this->withDeliveryCountAsRetryCount($decoded->with(new TransportMessageIdStamp($replyTo)), $message);
        }
    }

    /**
     * With retry_via=nak, gives a redelivered message the retry count Symfony's retry strategy reads: the count it
     * was published with, plus the deliveries before this one.
     *
     * NATS redelivers the message as it was published, so without this every failure is Symfony's first: the
     * multiplier never applies and max_retries never ends the retries (#109). The deliveries come from the
     * delivery's reply subject, so a delivery nobody settled (a crashed worker, an ack_wait that ran out) counts
     * as a retry too. A message published with a count, such as a retry copy from retry_via=copy that a worker
     * with retry_via=nak receives after a change of the option, has made those retries already, so they are
     * added rather than replaced: taking the higher of the two would hold the count while the deliveries catch
     * up, repeating one delay and allowing that many retries more.
     */
    private function withDeliveryCountAsRetryCount(Envelope $envelope, NatsMessage $message): Envelope
    {
        if ($this->configuration->retryVia() !== RetryVia::NAK) {
            return $envelope;
        }

        $delivered = JsMessageMetadata::fromMessage($message)?->numDelivered;
        if ($delivered === null || $delivered <= 1) {
            return $envelope;
        }

        return $envelope->with(new RedeliveryStamp(RedeliveryStamp::getRetryCountFromEnvelope($envelope) + $delivered - 1));
    }

    /**
     * Keeps the headers of a received message whose name is a string, as the serializer's decode() takes
     * string names.
     *
     * The client returns a header name that is a decimal integer, such as `1` or `-5`, as an int key, since PHP
     * stores such array keys as integers, so no string key can hold it, and any publisher can send such a header.
     * Symfony's serializers use only the type and stamp headers and this transport's use none, while a
     * serializer that relies on string names fails on such a name, and get() then rejects the message.
     *
     * @param array<int|string, string> $headers A map from {@see NatsHeaders::fromWireBlock()}.
     *
     * @return array<string, string>
     */
    private static function stringNamedHeaders(array $headers): array
    {
        return array_filter($headers, static fn (int|string $name): bool => is_string($name), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Sends a NAK to request redelivery in JetStream.
     *
     * When a positive nak_delay is configured, the NAK carries that delay so JetStream waits before
     * redelivering (backoff) instead of redelivering immediately.
     *
     * @param string $id The replyTo address (JetStream delivery token)
     */
    protected function sendNak(string $id): void
    {
        $message = $this->buildAckMessage($id);
        $nakDelayMs = $this->configuration->nakDelayMs();

        if ($nakDelayMs > 0) {
            $this->awaitOnConnection(fn (): Future => $this->jetStream()->nakWithDelay($message, $nakDelayMs));

            return;
        }

        $this->awaitOnConnection(fn (): Future => $this->jetStream()->nak($message));
    }

    /**
     * NAKs a delivery whose retry copy {@see send()} left out, so NATS redelivers it.
     *
     * With retry_via=nak the NAK carries the delay Symfony's retry strategy computed, cut only where it would no
     * longer fit the client's nanoseconds, and a delay of 0 is a plain NAK, which NATS redelivers at once;
     * nak_delay plays no part. The last delivery max_deliver allows never gets here: send() publishes its retry
     * copy ({@see isRetryNatsRedeliversItself()}). In nats mode (no delay from Symfony, null) it is
     * {@see sendNak()}.
     *
     * @param string   $id      The replyTo address (JetStream delivery token)
     * @param int|null $delayMs Symfony's delay in milliseconds, or null in nats mode
     */
    private function nakForRetry(string $id, ?int $delayMs): void
    {
        if ($delayMs === null) {
            $this->sendNak($id);

            return;
        }

        $message = $this->buildAckMessage($id);
        if ($delayMs > 0) {
            $this->awaitOnConnection(fn (): Future => $this->jetStream()->nakWithDelay($message, min($delayMs, self::MAX_NAK_DELAY_MS)));

            return;
        }

        $this->awaitOnConnection(fn (): Future => $this->jetStream()->nak($message));
    }

    /**
     * Sends TERM to stop JetStream redelivery for a failed delivery.
     *
     * Used when retry handling is delegated to Symfony (the default): the message
     * is terminated in JetStream so it won't be redelivered by NATS.
     *
     * @param string $id The replyTo address (JetStream delivery token)
     */
    protected function sendTerm(string $id): void
    {
        $this->awaitOnConnection(fn (): Future => $this->jetStream()->term($this->buildAckMessage($id)));
    }

    /**
     * Acknowledges successful handling of a received envelope.
     *
     * Extracts the JetStream delivery token from the envelope's TransportMessageIdStamp
     * and sends an ACK to JetStream so the message won't be redelivered. When the
     * {@see NatsTransportConfiguration::isAckSyncEnabled()} option is on, the ACK waits for
     * server confirmation (double-ack) so a dropped ACK cannot silently cause redelivery.
     *
     * A delivery whose retry copy {@see send()} left out, in nats mode or with retry_via=nak, is NAKed instead, so
     * NATS redelivers it: Symfony Messenger 7.4.22, 8.1.10 and later acknowledge a message they sent for retry
     * (#90). With retry_via=nak the NAK carries Symfony's delay ({@see nakForRetry()}).
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function ack(Envelope $envelope): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());

        $retry = $this->natsRedeliversTheRetryOf($envelope, $id);
        if ($retry !== null) {
            $this->checkTheConnectionBeforeSettlingAFailure();
            if ($this->isAMessageNatsModeCannotDecode($envelope)) {
                $this->sendTerm($id);

                return;
            }

            $this->nakForRetry($id, $retry['delayMs']);

            return;
        }

        $message = $this->buildAckMessage($id);

        if ($this->configuration->isAckSyncEnabled()) {
            $this->awaitOnConnection(fn (): Future => $this->jetStream()->ackSync($message));

            return;
        }

        $this->awaitOnConnection(fn (): Future => $this->jetStream()->ack($message));
    }

    /**
     * Rejects a received envelope according to configured retry strategy.
     *
     * Delegates to {@see handleFailedDelivery()}: sends TERM when using Symfony retry
     * handling or NAK when using NATS-native redelivery. A delivery whose retry copy {@see send()} left out, which
     * Symfony Messenger up to 7.4.21 and 8.1.9 rejects, is NAKed, with Symfony's delay under retry_via=nak
     * ({@see nakForRetry()}).
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function reject(Envelope $envelope): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());
        // The delivery whose retry copy send() left out, which Symfony Messenger up to 7.4.21 / 8.1.9 rejects.
        $retry = $this->natsRedeliversTheRetryOf($envelope, $id);
        $this->checkTheConnectionBeforeSettlingAFailure();

        // A failure Symfony retried is NAKed as ack() NAKs it, whatever its stamp says: Symfony Messenger up to 7.4.21
        // and 8.1.9 rejects the message it sent for retry, and later releases acknowledge it.
        if ($this->isAMessageNatsModeCannotDecode($envelope) || ($retry === null && $this->isAFailureNatsModeCannotRetry($envelope))) {
            $this->sendTerm($id);

            return;
        }

        if ($retry !== null) {
            $this->nakForRetry($id, $retry['delayMs']);

            return;
        }

        $this->handleFailedDelivery($id);
    }

    /**
     * Whether, in nats mode, the delivery being rejected failed with an exception Symfony marks as unrecoverable
     * (UnrecoverableExceptionInterface, such as UnrecoverableMessageHandlingException), which Symfony's
     * ErrorDetailsStamp names. Such a failure can never succeed, so it is TERMed, where nats mode NAKed it and NATS ran
     * the handler again on every delivery up to max_deliver, for ever without it: 4 runs with max_deliver 4 (measured
     * on nats-server 2.15.2). Ordinary failures are still NAKed, for NATS to redeliver under nak_delay, backoff and
     * max_deliver. The stamp names the first exception a HandlerFailedException wraps, which is the one that failed
     * when the message has one handler. With several handlers, Symfony's own retry strategy retries a
     * HandlerFailedException unless every exception in it is unrecoverable: a failure Symfony retried is NAKed, as
     * reject() reads it first, and one it did not retry (with max_retries: 0, which nats mode asks for, it retries
     * none) is TERMed when its first exception is unrecoverable, even when another handler's failure could succeed on
     * a redelivery. Symfony mode leaves that decision to Symfony.
     */
    private function isAFailureNatsModeCannotRetry(Envelope $envelope): bool
    {
        if ($this->configuration->retryHandler() !== RetryHandler::NATS) {
            return false;
        }

        $failedWith = $envelope->last(ErrorDetailsStamp::class)?->getExceptionClass();

        return $failedWith !== null && is_a($failedWith, UnrecoverableExceptionInterface::class, true);
    }

    /**
     * Whether, in nats mode, the delivery being settled is a message that could not be decoded, as Symfony Messenger
     * 8.1 and later deliver it: an envelope whose message is the MessageDecodingFailedException, which the worker
     * handles and fails like any message. It is TERMed rather than NAKed, as {@see get()} TERMs a message whose decode
     * throws: a redelivery cannot decode it either, and a NAK brought it back at once, for ever without max_deliver: two
     * workers of 3 s each got it 3769 times (Symfony Messenger 8.1.9, nats-server 2.14.2). In symfony mode Symfony's
     * retry strategy decides
     * about such a message as about any other, and its failure transport keeps it.
     */
    private function isAMessageNatsModeCannotDecode(Envelope $envelope): bool
    {
        return $this->configuration->retryHandler() === RetryHandler::NATS && $envelope->getMessage() instanceof MessageDecodingFailedException;
    }

    /**
     * Makes the NAK or TERM of a delivery whose handler failed check the connection first, as after a failed
     * operation ({@see connectionUsable()}): one PING, unless ping_after_idle is 0.
     *
     * An acknowledgement is a write that waits for no reply, and the client reads the socket only while an operation
     * waits for one. A server that closed the connection while the handler ran (a restart, a rolling upgrade, a
     * failover) leaves that close unread, the write of the NAK still succeeds, and the frame is lost: measured on the
     * test cluster (nats-server 2.15.1, client 2.27.2), the server stopped with `docker stop` inside the handler, the
     * client still reported the connection open 1.5 s later, and the message came back after ack_wait instead of
     * nak_delay. The PING reads the close, the client recovers the connection (or, without `reconnect`, the
     * transport dials again), and the NAK goes out on the new one, where it took effect after nak_delay. A handler
     * that failed is the likely moment for a lost connection, and a failure is rare enough to pay a round trip for;
     * the ACK of a handled message stays a plain write. Since client 2.28.0 the client sees such a close on a plain
     * socket before it writes, ACK included; the PING still covers the close it cannot see, behind bytes not read
     * yet, as behind the close_notify of a TLS server.
     */
    private function checkTheConnectionBeforeSettlingAFailure(): void
    {
        $this->checkBeforeNextUse = true;
    }

    /**
     * Whether the delivery being settled is one whose retry copy {@see send()} left out, in nats mode or with
     * retry_via=nak, which it then forgets: it is settled once, by the ack() of Symfony Messenger 7.4.22, 8.1.10
     * and later or the reject() of earlier releases, and both NAK it. Another delivery of the same message is not
     * that one.
     *
     * @return array{id: string, delayMs: int|null}|null The remembered delivery, or null when this is not it
     */
    private function natsRedeliversTheRetryOf(Envelope $envelope, string $id): ?array
    {
        $message = $envelope->getMessage();
        $retry = $this->retriesNatsRedelivers[$message] ?? null;
        if ($retry === null || $retry['id'] !== $id) {
            return null;
        }

        unset($this->retriesNatsRedelivers[$message]);

        return $retry;
    }

    /**
     * Signals to JetStream that a received message is still being processed.
     *
     * Sends an in-progress (+WPI) acknowledgement so the server resets the redelivery timer,
     * preventing a long-running handler from losing its message to ack_wait expiry before it
     * finishes. NATS resets the timer to the consumer's configured ack_wait, so the $seconds
     * hint from Symfony is advisory and not forwarded.
     *
     * The acknowledgement is queued, not waited for. Symfony's `messenger:consume --keepalive` calls this
     * from its SIGALRM handler, which interrupts the message handler wherever it is, and PHP does not allow
     * switching fibers inside a signal handler: waiting there failed with "Cannot switch fibers in current
     * execution context" at the first alarm, which stopped the worker or failed the message being handled
     * (#48). Queued, it goes out the next time the event loop runs: at once when the handler waits on
     * something asynchronous, such as a NATS call, and only after the handler returns when it does plain PHP
     * work, in which case ack_wait has to cover the handler instead. For the same reason nothing is dialled
     * or checked first, and a transport without a connection sends nothing.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());

        // Nobody waits for it, so a failure stays on its own fiber instead of reaching the event loop; the
        // operation that next needs the connection finds out whether it is still there.
        $this->jetStream?->inProgress($this->buildAckMessage($id))->ignore();
    }

    /**
     * Closes the NATS connection and releases the transport's resources.
     *
     * No-op when no connection was ever opened (the connection is lazy). After closing, the next
     * transport operation reconnects lazily via {@see jetStream()}, so the transport stays reusable.
     */
    public function close(): void
    {
        if ($this->jetStream === null) {
            return;
        }

        $this->client->disconnect()->await();
        $this->jetStream = null;
        $this->lastUsedAt = null;
        $this->checkBeforeNextUse = false;
        $this->consumerUnreadableOnThisConnection = false;

        // The next operation reconnects lazily, so let auto_setup verify provisioning once more on the
        // reopened connection. Without this the flag would stay latched for the lifetime of the object
        // and a stream removed while the transport was closed would never be recreated.
        $this->autoSetupDone = false;
    }

    /**
     * Opens NATS connection and initializes JetStream context.
     */
    protected function connect(): void
    {
        $this->client->connect()->await();
        $this->jetStream = $this->client->jetStream();
    }

    /**
     * Returns an approximate count of messages still to be processed.
     *
     * Tries the consumer info first and returns num_ack_pending + num_pending: those two
     * counts describe disjoint sets (delivered-but-unacked vs. not-yet-delivered), so the
     * total outstanding work is their sum - the accurate measure of work left for this consumer.
     *
     * Falls back to the stream-level message count when consumer info is unavailable (e.g. the
     * consumer has not been created yet), then to 0 if both queries fail. Treat that fallback as a
     * loose upper bound, not an exact backlog: under the default limits retention policy the stream
     * retains already-acknowledged messages, so the count can stay above 0 even when nothing is left
     * to process. The consumer-info path does not have this limitation.
     *
     * When NATS cannot be reached or refuses the login it returns 0, the same as for an empty queue, so
     * `messenger:stats` shows 0 then. Without reconnect: true it gives up after a single connection attempt.
     * With it the client's attempt loop runs first, unless the server itself refused the login.
     */
    public function getMessageCount(): int
    {
        try {
            $consumerInfo = $this->awaitOnConnection(fn (): Future => $this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer()));
            $ackPending = TypeCoercion::intValue($consumerInfo->raw['num_ack_pending'] ?? 0);
            $pending = TypeCoercion::intValue($consumerInfo->raw['num_pending'] ?? 0);

            return $ackPending + $pending;
        } catch (\Throwable) {
            // No connection could be opened: the stream lookup would only dial again and fail the same way,
            // which doubled the wait for a server that is down (#54). A lookup that failed on an open
            // connection still falls back, on a new connection if that one turns out to be gone.
            if ($this->jetStream === null) {
                return 0;
            }

            try {
                $streamInfo = $this->awaitOnConnection(fn (): Future => $this->jetStream()->getStream($this->streamName));
                $state = is_array($streamInfo->raw['state'] ?? null) ? $streamInfo->raw['state'] : [];

                return TypeCoercion::intValue($state['messages'] ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        }
    }

    /**
     * Ensures stream and consumer are present and configured.
     *
     * Creates the JetStream stream with the configured retention limits (max age,
     * bytes, messages, replicas). If the stream already exists, it is updated with
     * the new settings. Then creates a durable pull consumer with explicit ACK
     * policy and validates it matches expectations.
     *
     * @throws RuntimeException If stream or consumer setup fails
     */
    public function setup(): void
    {
        try {
            $subjects = $this->buildDesiredSubjects();
            $streamConfiguration = $this->buildManagedStreamConfiguration($subjects);

            if ($this->configuration->isScheduledMessagesEnabled()) {
                $this->connectIfNeeded();
                $unsupported = $this->schedulesUnsupportedByTheServer();
                if ($unsupported !== null) {
                    throw $unsupported;
                }

                $this->assertTheStreamToSetUpKeepsDelayedMessages($streamConfiguration);
            }

            try {
                $streamInfo = $this->awaitOnConnection(fn (): Future => $this->jetStream()->addStream($streamConfiguration));
            } catch (UnsupportedFeatureException $unsupportedFeature) {
                // A version-gated feature (e.g. allow_msg_schedules) was rejected by an older server.
                // That is not a pre-existing-stream conflict, so skip the existence check and surface
                // an actionable message via the outer handler.
                throw $unsupportedFeature;
            } catch (JetStreamException $streamCreateException) {
                // Don't string-match the server's error text to detect a pre-existing stream - the
                // message wording varies across NATS versions. Ask JetStream directly: if the stream
                // now exists, update it (reusing the fetched config); if it does not (404), the create
                // failure was a genuine error, so rethrow it.
                $existingStream = $this->getExistingStream();
                if ($existingStream === null) {
                    throw $streamCreateException;
                }

                // The update API takes a raw config array merged with the live server config, so derive
                // the managed fields from the same StreamConfiguration (dropping name/subjects, which
                // the update path handles itself).
                $managedOptions = $streamConfiguration->toArray();
                unset($managedOptions['name'], $managedOptions['subjects']);

                $updatedConfiguration = $this->buildUpdatedStreamConfiguration($existingStream, $managedOptions, $subjects);
                $streamInfo = $this->awaitOnConnection(fn (): Future => $this->jetStream()->updateStream($this->streamName, $updatedConfiguration));
            }

            // The live stream: an update leaves an existing stream's retention as it was.
            if ($this->warnAboutWhatMaxDeliverStops) {
                $this->warnIfNatsModeLosesWhatMaxDeliverStops($streamInfo);
            }

            $consumerConfiguration = ConsumerConfiguration::create()
                ->durable($this->configuration->consumer())
                ->filterSubject($this->topic)
                ->ackPolicy(AckPolicy::Explicit)
                ->deliverPolicy(DeliverPolicy::All);

            // Optional NATS-native redelivery tuning (primarily relevant with retry_handler=nats).
            $ackWaitMs = $this->configuration->ackWaitMs();
            if ($ackWaitMs !== null) {
                $consumerConfiguration->ackWait($ackWaitMs);
            }

            $maxDeliver = $this->configuration->maxDeliver();
            if ($maxDeliver !== null) {
                $consumerConfiguration->maxDeliver($maxDeliver);
            }

            $backoffMs = $this->configuration->backoffMs();
            if ($backoffMs !== null) {
                $consumerConfiguration->backoff($backoffMs);
            }

            $maxAckPending = $this->configuration->maxAckPending();
            if ($maxAckPending !== null) {
                $consumerConfiguration->maxAckPending($maxAckPending);
            }

            $inactiveThresholdMs = $this->configuration->inactiveThresholdMs();
            if ($inactiveThresholdMs !== null) {
                $consumerConfiguration->inactiveThreshold($inactiveThresholdMs);
            }

            // A setup() that changed stream_replicas reaches the consumer while NATS may still be moving it.
            $replayPolicy = $this->whileTheClusterSettles($this->resolveReplayPolicy(...));
            if ($replayPolicy !== null) {
                $consumerConfiguration->replayPolicy($replayPolicy);
            }

            $consumerInfo = $this->whileTheClusterSettles(fn (): ConsumerInfo => $this->awaitOnConnection(fn (): Future => $this->jetStream()->addConsumer($this->streamName, $consumerConfiguration)));
            $this->assertConsumerMatchesConfiguration($consumerInfo);
        } catch (UnsupportedFeatureException $unsupportedFeature) {
            throw new RuntimeException($this->describeUnsupportedFeature($unsupportedFeature), 0, $unsupportedFeature);
        } catch (JetStreamException $rejection) {
            // Caught here, in the outer chain, and not around the update call: an inner rethrow would be
            // wrapped once more by the arm below, doubling the prefix and burying the client exception.
            throw new RuntimeException($this->describeSetupRejection($rejection), 0, $rejection);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to setup NATS stream '{$this->streamName}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts transport message ID stamp from an envelope.
     */
    private function findReceivedStamp(Envelope $envelope): TransportMessageIdStamp
    {
        /** @var TransportMessageIdStamp|null $receivedStamp */
        $receivedStamp = $envelope->last(TransportMessageIdStamp::class);

        if (null === $receivedStamp) {
            throw new LogicException('No TransportMessageIdStamp found on the Envelope.');
        }

        return $receivedStamp;
    }

    /**
     * Provisions the stream and consumer on first use when auto_setup is enabled.
     *
     * {@see setup()} runs at most once per transport instance, lazily, from the first
     * {@see send()}/{@see get()}. A no-op when auto_setup is disabled (the default), so the
     * stream/consumer must then be provisioned explicitly via `messenger:setup-transports`. The
     * done-flag is set only after {@see setup()} succeeds, so a transient provisioning failure is
     * retried on the next call rather than silently skipped.
     *
     * A setup for a send does not warn about a nats-mode stream that loses what max_deliver stops: that warning is
     * for the operator, who runs `messenger:setup-transports` or a worker, and a process that only sends sets the
     * stream up once per transport object, which is once per request under PHP-FPM.
     */
    private function autoSetupIfEnabled(bool $toSend = false): void
    {
        if (!$this->configuration->isAutoSetupEnabled()) {
            return;
        }

        // Connect first. Dialling again after the client closed clears the done-flag, so the new
        // connection is verified in this same call rather than the next one, and a dial that fails
        // surfaces as the connection error it is rather than as a failed setup.
        $this->connectIfNeeded();

        if ($this->autoSetupDone && !$this->clientReconnectedSinceSetup()) {
            return;
        }

        $this->warnAboutWhatMaxDeliverStops = !$toSend;
        try {
            $this->setup();
        } finally {
            $this->warnAboutWhatMaxDeliverStops = true;
        }
        $this->autoSetupDone = true;
        $this->reconnectsAtSetup = $this->clientOwnsRecovery() ? $this->client->statistics()->reconnects : null;
    }

    /**
     * Whether the client reconnected on its own since auto_setup last verified provisioning.
     *
     * The done flag is cleared when the transport replaces the connection ({@see dropConnection()}, {@see close()}),
     * so that a stream lost during an outage is recreated on the new connection. A client that owns recovery
     * ({@see clientOwnsRecovery()}) replaces the connection behind the transport's back, and the flag stayed
     * latched: with a memory stream over a server restart, every send() after the client's reconnect failed with
     * JetStream 503 "No JetStream responder" for the rest of the process (#81). The client's reconnect count,
     * which only ever grows for one client object, tells the two connections apart.
     */
    private function clientReconnectedSinceSetup(): bool
    {
        return $this->reconnectsAtSetup !== null && $this->client->statistics()->reconnects > $this->reconnectsAtSetup;
    }

    /**
     * Re-runs provisioning after JetStream reported the stream or consumer as missing.
     *
     * Only meaningful with auto_setup: that option makes the transport responsible for the stream and
     * consumer existing, so it also has to cope with them disappearing while the process runs. The
     * common cause is inactive_threshold, after which NATS removes an idle durable consumer; without
     * this, a low-traffic worker would keep pulling from a consumer that is gone and report an empty
     * queue forever.
     *
     * Returns false when auto_setup is disabled, in which case provisioning is the operator's job and
     * the caller should treat the missing resource as an empty result, exactly as before.
     */
    private function reprovisionForAutoSetup(): bool
    {
        if (!$this->configuration->isAutoSetupEnabled()) {
            return false;
        }

        $this->autoSetupDone = false;
        $this->autoSetupIfEnabled();

        return true;
    }

    /**
     * Decides what a failed batch pull means, re-provisioning once when auto_setup owns the resources.
     *
     * Returns the messages from a successful retry, or null when the batch should be reported as empty.
     * Anything it cannot account for is rethrown.
     *
     * The status codes matter and are easy to get wrong. A deleted durable consumer does NOT produce a
     * 404: nothing is subscribed to answer the pull request, so the client reports 503. Measured
     * directly against nats-server 2.10.29 and 2.14.2 by deleting the consumer and pulling:
     * "JetStream pull request ended with status 503". 409 is what a consumer deleted mid-pull reports.
     * A 404 reports a pull that found no messages, but re-provisioning on it as well is harmless when
     * the resources exist, so all three are treated as a sign that the consumer or stream it pulls from
     * may not be there. A 503 is that sign only from a server that stays: one that is going away sends the
     * same 503 for a consumer it has already stopped, and that pull is an empty batch
     * ({@see pullMetAServerGoingAway()}).
     *
     * @return list<NatsMessage>|null
     */
    private function recoverFromFetchFailure(JetStreamException $exception): ?array
    {
        $code = $exception->getCode();

        // A pull that timed out found no messages, or got no answer at all, after which the next operation checks
        // the connection first, unless ping_after_idle is 0 ({@see awaitOnConnection()}).
        if ($code === 408) {
            return null;
        }

        if ($this->pullEndedByTheServer($exception) || $this->pullMetAServerGoingAway($exception)) {
            return null;
        }

        $missingResource = $code === 404 || $code === 409 || $code === 503;

        if ($missingResource && $this->reprovisionForAutoSetup()) {
            try {
                return $this->fetchBatchMessages();
            } catch (JetStreamException $retryException) {
                // One re-provisioning attempt and one retry. The retry deliberately accepts a narrower
                // set of codes than the recovery above: setup() just recreated the consumer, so at this
                // point a 408 is a normal empty pull and a 404 keeps the historical empty-queue read,
                // but a repeated 503 or 409 no longer means "missing" - it means pulls are failing on a
                // consumer that verifiably exists, and flattening that into an empty batch would hide
                // a real outage behind a worker that forever reports nothing to do. A pull the server
                // ended itself, or whose 503 came from a server going away, is as empty here as on the first try.
                if ($retryException->getCode() === 404 || $retryException->getCode() === 408 || $this->pullEndedByTheServer($retryException) || $this->pullMetAServerGoingAway($retryException)) {
                    return null;
                }

                throw $retryException;
            }
        }

        // Without auto_setup the operator owns provisioning: a 404 reads as an empty queue, as it always
        // has, and everything else propagates, the 503 of a missing consumer included.
        if ($code === 404) {
            return null;
        }

        throw $exception;
    }

    /**
     * Whether a 409 ends the pull for a reason of the server's, with the consumer unharmed, and arms the connection
     * check when that reason is the server's shutdown.
     *
     * A server that is shutting down ends every pull in flight with "409 Server Shutdown", and a consumer whose
     * leader moved to another server ends them with "409 Leadership Change". Neither means the consumer is missing,
     * which is what every other 409 used to be read as: with auto_setup, setup() then ran against the server that
     * was going away and failed, so a worker exited with "Failed to setup NATS stream" on every graceful stop of its
     * server, and without auto_setup the 409 propagated and ended the worker as well (#81). Both read as an empty
     * batch now. The shutting-down server closes the connection right after, so the operation after a shutdown
     * checks the connection first, as after any failure on it; after a leadership change the connection is fine.
     *
     * Only these two descriptions. "Consumer Deleted" keeps its reading as a missing consumer, which auto_setup
     * recreates, and the 409s that report configuration ("Consumer is push based", "Exceeded MaxRequestBatch") stay
     * visible: no amount of polling heals them, and an empty batch would hide a misconfigured worker behind "nothing
     * to do". The client classifies the same statuses for its own iterator, in a helper it marks internal.
     */
    private function pullEndedByTheServer(JetStreamException $exception): bool
    {
        if ($exception->getCode() !== 409) {
            return false;
        }

        if (stripos($exception->getMessage(), 'Server Shutdown') !== false) {
            $this->checkBeforeNextUse = true;

            return true;
        }

        return stripos($exception->getMessage(), 'Leadership Change') !== false;
    }

    /**
     * Whether a pull's 503 came from a server that is going away, rather than from a missing consumer, and arms the
     * connection check when it may have (#106).
     *
     * nats-server answers a pull with 503 (no responders) whenever nothing is subscribed to the consumer's pull subject:
     * when the consumer does not exist, and also while a server that is going away has stopped the consumer and not yet
     * closed the connection. In a graceful stop that moment lasts about 10 ms for each durable consumer on a file stream
     * on NATS 2.15; in lame duck mode it lasts the whole grace period (10 s by default, on every version), since the
     * server stops JetStream at once and keeps the connections open. The pull alone cannot tell them apart. The
     * client's diagnoseNoResponders() (client 2.29) does: it asks for the consumer while it watches the connection. A
     * server that stays answers; one that is going away answers nothing, and closes the connection or announces lame
     * duck mode, which ends the wait without request_timeout, also when the client keeps the question across its
     * reconnect.
     *
     * ServerGoingAway is an empty batch, and the operation after it checks the connection first, as after
     * `409 Server Shutdown` (a recovery the client runs is left to it). Any answer - the consumer, a 404 or another
     * JetStream error - comes from a server that stays, and the 503 keeps its reading as a missing consumer: with
     * auto_setup the consumer is provisioned again and pulled from once more, without it the 503 propagates. So does
     * NoAnswer, on a connection that stays (a user allowed to pull but not to ask for consumer info, which the server
     * leaves unanswered), after request_timeout: reading it as empty would hide a missing consumer behind a worker that
     * never stops polling. The operation after it checks the connection first, as after any that failed on it.
     */
    private function pullMetAServerGoingAway(JetStreamException $exception): bool
    {
        if ($exception->getCode() !== 503 || $this->jetStream === null) {
            return false;
        }

        $cause = $this->jetStream->diagnoseNoResponders($this->streamName, $this->configuration->consumer())->await();
        if ($cause === NoRespondersCause::ServerGoingAway || $cause === NoRespondersCause::NoAnswer) {
            $this->checkBeforeNextUse = true;
        }

        return $cause === NoRespondersCause::ServerGoingAway;
    }

    /**
     * Pulls one raw batch from the configured durable consumer.
     *
     * A pull needs a second subscription on the connection, its reply inbox, next to the reply inbox of the
     * transport's requests (a send, an ack_sync acknowledgement, setup() or getMessageCount() subscribes that
     * one). With a subscription limit of one, the server refuses the pull's inbox with "maximum subscriptions
     * exceeded". The client's SubscriptionLimitException becomes Symfony's TransportException, which
     * ReceiverInterface::get() declares, naming the stream, the consumer and the limit, with the client's
     * exception as its previous one (#76). Here rather than in get(), so the second pull after re-provisioning
     * gets the same treatment. The pull still counts as an operation that failed on the connection
     * ({@see awaitOnConnection()}): the server sends the same error when an account's limit is lowered below what
     * the connection holds, and then closes the connection right behind it.
     *
     * @return list<NatsMessage>
     */
    private function fetchBatchMessages(): array
    {
        $jetStream = $this->jetStream();

        try {
            return $this->awaitOnConnection(fn (): Future => $jetStream->fetchBatch(
                $this->streamName,
                $this->configuration->consumer(),
                $this->configuration->batching(),
                $this->configuration->maxBatchTimeoutMs()
            ));
        } catch (SubscriptionLimitException $limit) {
            throw new TransportException(sprintf(
                'Cannot pull from NATS stream "%s", consumer "%s": the subscription limit was exceeded. '
                . 'This transport requires capacity for at least two subscriptions per connection. '
                . 'Check server, account and user limits.',
                $this->streamName,
                $this->configuration->consumer(),
            ), previous: $limit);
        } finally {
            // A pull may wait up to max_batch_timeout for messages, and the connection was in use all that time,
            // whether messages came or the pull ended empty, which throws. Counted only on success, an empty pull
            // longer than ping_after_idle made every next pull PING first.
            $this->lastUsedAt = $this->monotonicSeconds();
        }
    }

    /**
     * Connects when the transport has no connection it can use: none was opened yet, the client has closed,
     * or the connection sat idle or just failed an operation and does not answer a PING
     * ({@see connectionUsable()}).
     *
     * Called internally by {@see jetStream()} before every JetStream call. Unless `reconnect` is on, the client
     * runs with reconnect off, so a connection the server closed or one that dropped leaves it in its terminal
     * Closed state, as do refused credentials, and it then refuses every request. Dialling again is what keeps
     * a long-lived process working after that (#49): a consumer worker exits on the failed get() and its
     * supervisor starts a new one, but a process that sends for longer than one request - a web app in
     * worker mode, a daemon, a handler that dispatches - used to fail every later operation until it was
     * restarted. The client releases what it held on every terminal close, so a fresh connect() starts
     * clean. As after {@see close()}, auto_setup verifies provisioning again on the new connection. With
     * `reconnect` on the client recovers a lost connection itself and the transport leaves it to that
     * ({@see clientOwnsRecovery()}); a client that gave up is Closed and dialled again the same way, which in
     * that mode runs the client's attempt loop.
     */
    private function connectIfNeeded(): void
    {
        if ($this->jetStream !== null && !$this->connectionUsable()) {
            $this->dropConnection();
        }

        if ($this->jetStream === null) {
            $this->connect();
        }
    }

    /**
     * Whether the transport can keep using its connection.
     *
     * Not once the client has closed. Nor when the connection went unused for longer than ping_after_idle,
     * or an operation failed on it, and the server does not answer a PING within connection_timeout. A
     * server drops a client that stops answering its pings, which a PHP process does whenever it is outside
     * a transport call, and load balancers and NAT gateways drop idle connections too, all without the
     * client noticing until it writes. Checking first means the operation runs on a new connection instead
     * of failing on the old one.
     */
    private function connectionUsable(): bool
    {
        $state = $this->client->state();
        if ($state === ConnectionState::Closed) {
            return false;
        }

        // A client in the middle of its own recovery is left to it ({@see clientOwnsRecovery()}). A PING now would
        // go unanswered, and the dial that followed would stop the recovery and run the client's whole attempt loop
        // inside this operation. The operation waits for the recovery within its own budget instead: a request
        // within request_timeout, a pull within max_batch_timeout and a second.
        if ($state === ConnectionState::Connecting && $this->clientOwnsRecovery()) {
            return true;
        }

        $idleLimit = $this->configuration->pingAfterIdleSeconds();
        if ($idleLimit <= 0.0) {
            return true;
        }

        $idle = $this->lastUsedAt !== null && $this->monotonicSeconds() - $this->lastUsedAt > $idleLimit;
        if (!$idle && !$this->checkBeforeNextUse) {
            return true;
        }

        // What a failed PING is read against when the client owns recovery, see keptAfterFailedPing(). Read only
        // then: the count is the client's, and nothing else here needs it.
        $reconnectsBeforePing = $this->clientOwnsRecovery() ? $this->client->statistics()->reconnects : null;

        $ping = $this->client->rtt();
        // When the wait below gives up, the PING still ends later, with nobody awaiting it.
        $ping->ignore();
        try {
            $ping->await(new TimeoutCancellation($this->configuration->connectionTimeoutSeconds()));
        } catch (\Throwable) {
            return $reconnectsBeforePing !== null && $this->keptAfterFailedPing($reconnectsBeforePing);
        }

        $this->lastUsedAt = $this->monotonicSeconds();
        $this->checkBeforeNextUse = false;

        return true;
    }

    /**
     * Whether the client owns the recovery of a lost connection, as it does with `reconnect: true`.
     *
     * Without the option the client runs with reconnect off: a lost connection leaves it Closed, the operation that
     * ran into it fails, and the transport dials again on the next one ({@see connectIfNeeded()}). With it, the
     * client re-dials on its own, with its backoff and up to max_reconnect_attempts times, replays its subscriptions
     * and flushes the frames it buffered meanwhile, and the transport steps back: it never ends a recovery in
     * flight, keeps a connection the client has already recovered, verifies provisioning again after a reconnect
     * ({@see autoSetupIfEnabled()}), and only replaces a connection the client does not know to be lost
     * ({@see keptAfterFailedPing()}). A client that gave up is Closed, and the next operation dials again as without
     * the option; in this mode that dial runs the client's attempt loop, which is the wait the option asks for.
     */
    private function clientOwnsRecovery(): bool
    {
        return $this->configuration->isReconnectEnabled();
    }

    /**
     * After a PING that failed or went unanswered, with the client owning recovery: whether the connection stays.
     *
     * The failure alone does not say what the client made of it. Four cases, told apart by the client's state and
     * by its reconnect count from before the PING:
     *
     *  - Connecting: the PING's write failed and the client is recovering. Kept. The operation waits for the
     *    recovery within its own budget, and the check stays armed for the operation after it.
     *  - Open, with a higher reconnect count: the client already recovered, and the PONG that never came belonged
     *    to the lost connection. Kept, and as good as checked. Provisioning is verified again when auto_setup is on,
     *    since the stream may have gone with the server ({@see autoSetupIfEnabled()}).
     *  - Open, with the same count: the same connection, and it delivers nothing. Nothing in the client recovers
     *    it before its heartbeat has gone unanswered (maxPingsOut + 1) times, about 90 s at the client's defaults,
     *    and the heartbeat only runs while an operation holds the event loop. Replaced, as without the option.
     *  - Closed: the recovery gave up. Replaced; the dial runs the client's attempt loop.
     *
     * Measured with the real client over a scripted connection in ReconnectModeTest: keeping the connection in the
     * third case left every later send timing out, and replacing it in the second ended a connection that worked.
     */
    private function keptAfterFailedPing(int $reconnectsBeforePing): bool
    {
        $state = $this->client->state();
        if ($state === ConnectionState::Connecting) {
            return true;
        }

        if ($state === ConnectionState::Open && $this->client->statistics()->reconnects > $reconnectsBeforePing) {
            $this->lastUsedAt = $this->monotonicSeconds();
            $this->checkBeforeNextUse = false;

            return true;
        }

        return false;
    }

    /**
     * Awaits a call on the connection. When it fails other than with a JetStream reply, which proves the
     * server answered, the next operation checks the connection with a PING before using it. The client
     * may still report the connection Open after its socket died - releases before 2.10 after a failed
     * write, 2.10.0 after the server's fatal -ERR, and from 2.10.3 after 'maximum subscriptions exceeded'
     * when the server closes the connection right behind it, as it does when an account's subscription
     * limit is lowered - and a connection that silently stopped delivering only times out; without the
     * check, every operation after such a failure failed the same way (#49). A pull that got no
     * answer at all ({@see UNANSWERED_PULL}) counts as such a failure, though the client reports it as a
     * JetStream error: a half-open connection takes every write and delivers nothing, so without the check
     * each pull on it ended empty, and a consumer reported nothing to do until the heartbeat noticed.
     *
     * A pull refused at the subscription limit counts as a failure too (#76). The server refuses a SUB beyond the
     * limit and keeps the connection open, but it sends the same error when an account's limit is lowered below
     * what the connection holds, and then closes the connection about 20 ms later. The error cannot tell the two
     * apart, so the check stays: an open connection answers the PING and is kept, a closed one is replaced.
     *
     * The call is passed as a closure that starts it, not as its Future (#115). With zend.exception_ignore_args off,
     * the default without a php.ini, the trace of an exception raised in the event loop records the arguments of
     * every frame waiting on it, and a Future recorded there, holding that exception, makes a cycle that only the
     * cycle collector frees: one per pull that finds no messages. So the closure starts the call itself. One that
     * captures a Future made before it brings the cycle back, and so does a helper that takes the Future as a
     * parameter. The closure runs before the try, as the argument expression did, so a call that throws before it
     * returns a Future, such as a refused dial or a name the client refuses, leaves the connection check as it was.
     *
     * @template T
     * @param \Closure(): Future<T> $call
     * @return T
     */
    private function awaitOnConnection(\Closure $call): mixed
    {
        $future = $call();

        try {
            return $future->await();
        } catch (JetStreamException $reply) {
            if (str_starts_with($reply->getMessage(), self::UNANSWERED_PULL)) {
                $this->checkBeforeNextUse = true;
            }

            throw $reply;
        } catch (\Throwable $failure) {
            $this->checkBeforeNextUse = true;

            throw $failure;
        }
    }

    /**
     * Gives up a connection the transport can no longer use, so that the next step dials afresh.
     */
    private function dropConnection(): void
    {
        // A client that has closed has already released its socket.
        if ($this->client->state() !== ConnectionState::Closed) {
            try {
                $this->client->disconnect()->await();
            } catch (\Throwable) {
                // Closed or not, the connection is of no use any more.
            }
        }

        $this->jetStream = null;
        $this->lastUsedAt = null;
        $this->checkBeforeNextUse = false;
        $this->consumerUnreadableOnThisConnection = false;
        // As after close(): auto_setup verifies provisioning again on the new connection, where a stream
        // removed during the outage is recreated instead of being trusted from the latched flag.
        $this->autoSetupDone = false;
    }

    /**
     * Returns the JetStream context, connecting lazily if needed.
     *
     * @throws LogicException If connection succeeds but JetStream context is still null
     */
    private function jetStream(): JetStreamContext
    {
        $this->connectIfNeeded();

        if ($this->jetStream === null) {
            throw new LogicException('JetStream context is not available.');
        }

        $this->lastUsedAt = $this->monotonicSeconds();

        return $this->jetStream;
    }

    /**
     * Monotonic time in seconds, for measuring how long the connection sat idle. Exposed for testability.
     */
    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Waits $seconds while the event loop goes on serving the connection. Exposed for testability.
     */
    protected function pause(float $seconds): void
    {
        delay($seconds);
    }

    /**
     * Applies configured failure strategy for failed deliveries.
     *
     * When {@see RetryHandler::NATS} is active, sends a NAK so JetStream redelivers
     * the message. Otherwise sends TERM so JetStream stops redelivery, allowing
     * Symfony's retry/failure transport to handle the failure.
     *
     * @param string $id The JetStream replyTo delivery token
     */
    private function handleFailedDelivery(string $id): void
    {
        if ($this->configuration->retryHandler() === RetryHandler::NATS) {
            $this->sendNak($id);

            return;
        }

        $this->sendTerm($id);
    }

    /**
     * Builds a minimal message wrapper used by JetStream ack/nak/term APIs.
     */
    private function buildAckMessage(string $replyTo): NatsMessage
    {
        return new NatsMessage(
            subject: $this->topic,
            sid: 0,
            replyTo: $replyTo,
            payload: ''
        );
    }

    /**
     * Verifies that the configured durable consumer was created with the expected pull settings.
     */
    private function assertConsumerMatchesConfiguration(ConsumerInfo $consumerInfo): void
    {
        if ($consumerInfo->streamName !== $this->streamName || $consumerInfo->name !== $this->configuration->consumer()) {
            throw new RuntimeException('Consumer was not created successfully.');
        }

        /** @var array<string, mixed> $config */
        $config = self::configOf($consumerInfo);

        if (($config['ack_policy'] ?? null) !== 'explicit') {
            throw new RuntimeException('Consumer ack policy must be explicit.');
        }

        if (($config['deliver_policy'] ?? null) !== 'all') {
            throw new RuntimeException('Consumer deliver policy must be all.');
        }

        if (($config['filter_subject'] ?? null) !== $this->topic) {
            throw new RuntimeException('Consumer filter subject does not match the configured topic.');
        }

        if ($consumerInfo->push) {
            throw new RuntimeException('Consumer must be configured as a pull consumer.');
        }
    }

    /**
     * Runs one of setup()'s consumer requests, and runs it again while the cluster is still moving the consumer,
     * pausing CLUSTER_SETTLE_PAUSE before each new attempt: for up to CLUSTER_SETTLE_SECONDS while it answers
     * err_code 10008, "JetStream system temporarily unavailable", or 10059, "stream not found", and once after a
     * request it did not answer at all. Any other error, either code still there at the end, and a second
     * unanswered request propagate as before.
     *
     * A stream update that changes the replica count makes NATS move the stream's consumers to its new set of
     * servers. It does that after it has answered the update, so setup() reaches the consumer in the middle of the
     * move. A consumer whose leader was on a server the stream left has no leader until a remaining server takes
     * over, and a consumer request in that window is answered with 10008 (measured on nats-server 2.10.29 with
     * stream_replicas going from 2 to 1: the window lasts milliseconds). Before this, that failed the setup, and
     * running it again passed. In the same window a request can also be told that the stream setup() has just
     * created or updated is not found, or get no answer within request_timeout (both seen in the same
     * measurements); a stream that really went away in between still fails the setup, after the window. Both
     * requests repeated here are reads or idempotent: the consumer lookup, and the consumer create with the same
     * configuration.
     *
     * @template T
     * @param \Closure(): T $request
     * @return T
     */
    private function whileTheClusterSettles(\Closure $request): mixed
    {
        $deadline = $this->monotonicSeconds() + self::CLUSTER_SETTLE_SECONDS;
        $unanswered = false;
        while (true) {
            try {
                return $request();
            } catch (JetStreamException $exception) {
                $errCode = $exception->getErrCode();
                if (($errCode !== self::JS_ERR_CLUSTER_NOT_AVAILABLE && $errCode !== self::JS_ERR_STREAM_NOT_FOUND) || $this->monotonicSeconds() >= $deadline) {
                    throw $exception;
                }
            } catch (TimeoutException $timeout) {
                // Already request_timeout long, so asked once more whatever the deadline says, and not again.
                if ($unanswered) {
                    throw $timeout;
                }
                $unanswered = true;
            }
            $this->pause(self::CLUSTER_SETTLE_PAUSE);
        }
    }

    /**
     * Returns the replay policy to write to the durable consumer, or null to leave the field out.
     *
     * NATS refuses to change the replay policy of an existing durable consumer ("replay policy can not
     * be updated"), and it refuses in BOTH directions. Once a consumer exists as `original`, dropping
     * the option again does not restore `instant`: the transport would then omit the field, the server
     * would read that as a change back to its default, and reject it just the same. Verified against
     * nats-server 2.10 and 2.14, where removing the option from a consumer created as `original` fails
     * every subsequent setup() until the consumer is deleted.
     *
     * So the existing consumer's own value always wins, whatever the DSN says. The configured option
     * applies only to a consumer that does not exist yet, exactly like stream_retention applies only to
     * a stream that does not exist yet. That keeps setup() idempotent no matter how the option is
     * changed on a live deployment.
     */
    private function resolveReplayPolicy(): ?ReplayPolicy
    {
        $existingConsumer = $this->getExistingConsumer();
        if ($existingConsumer === null) {
            return $this->configuration->replayPolicy();
        }

        /** @var array<string, mixed> $config */
        $config = self::configOf($existingConsumer);
        $currentPolicy = $config['replay_policy'] ?? null;

        // A consumer reporting no replay policy is on the server default, so leaving the field out
        // matches it and avoids sending a value an older server might not understand.
        return $currentPolicy === null ? null : ReplayPolicy::tryFrom(TypeCoercion::stringValue($currentPolicy));
    }

    /**
     * Returns the existing durable consumer, or null when it does not exist.
     *
     * Mirrors {@see getExistingStream()}: a 404 means the consumer is absent, anything else propagates. A 404 that
     * says the stream is not found (err_code 10059) propagates too: setup() has just created or updated the stream,
     * so the cluster is still moving it ({@see whileTheClusterSettles()}), and reading that as "no consumer" would
     * send the configured replay policy to a consumer that may have another one.
     */
    private function getExistingConsumer(): ?ConsumerInfo
    {
        try {
            return $this->awaitOnConnection(fn (): Future => $this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer()));
        } catch (JetStreamException $exception) {
            if ($exception->getCode() === 404 && $exception->getErrCode() !== self::JS_ERR_STREAM_NOT_FOUND) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Returns the existing stream, or null when it does not exist.
     *
     * Detects existence deterministically via a JetStream stream-info lookup (a 404 means the stream
     * is absent), instead of matching server-specific "already in use" / "already exists" error
     * strings, whose wording varies across NATS versions. Any non-404 error propagates.
     */
    private function getExistingStream(): ?StreamInfo
    {
        try {
            return $this->awaitOnConnection(fn (): Future => $this->jetStream()->getStream($this->streamName));
        } catch (JetStreamException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Describes a JetStream API rejection of {@see setup()}, adding what to do about the one the
     * transport can foresee.
     *
     * err_code 10123 ("can not move and scale a stream in a single update") comes back when one
     * STREAM.UPDATE changes the placement and the replica count: the server reads any difference in the
     * placement block as a request to move the stream and refuses a move combined with a scale, on
     * every version. The transport sends exactly that when stream_replicas and a placement option
     * change together on an existing stream, so the message says to stage the two changes and to wait
     * for the first one's replica migration, which the server runs in the background and does not
     * combine with a second change either. The update is not split here on purpose: with auto_setup a
     * setup() that applied one half and returned would latch the done flag and never apply the other,
     * and setup() has no way to wait for a migration. Only the update can produce the code, since
     * creating a stream or a consumer moves nothing.
     *
     * Every other rejection keeps the plain wording with the server's message, so a placement no server
     * can satisfy (err_code 10005, "no suitable peers for placement") reads as the server put it.
     */
    private function describeSetupRejection(JetStreamException $exception): string
    {
        $message = "Failed to setup NATS stream '{$this->streamName}': " . $exception->getMessage();

        if ($exception->getErrCode() !== self::JS_ERR_STREAM_MOVE_AND_SCALE) {
            return $message;
        }

        return $message
            . '. NATS refuses to change the placement and the replica count of a stream in one update.'
            . ' Stage the two changes: run the setup with only one of them (for example the placement'
            . ' options as they were and the new stream_replicas), wait until "nats stream info" shows'
            . ' every replica as current, then run it again with the other change. With auto_setup every'
            . ' send() and get() repeats this update and fails until the configuration is staged.';
    }

    /**
     * Logs, in nats mode with max_deliver, that the stream is one where NATS deletes a message that reached max_deliver.
     * In that mode the message is the only record of a message that kept failing: Symfony's failure transport gets a
     * copy of every failed delivery with max_retries: 0, or none with retries left on (README, "Retry Handler
     * Behavior"). Measured on nats-server 2.10.29 to 2.15.2 (docs/symfony_retry_via.md), NATS deletes it:
     *
     * - from a stream with interest retention on a clustered server, which the stream info's cluster name tells: with
     *   replicas within seconds to about two minutes, with one replica at a restart;
     * - from one on a standalone server at a restart, before 2.12.9 and on 2.14.0;
     * - from a work-queue stream with more than one replica before 2.12.5.
     *
     * Nats mode keeps its behaviour in 5.x, so this only says so, once per setup(), and names the way out: retry_via:
     * nak with a failure transport, or a stream with limits or work-queue retention. The version is the connected
     * server's.
     */
    private function warnIfNatsModeLosesWhatMaxDeliverStops(?StreamInfo $stream): void
    {
        if ($this->configuration->retryHandler() !== RetryHandler::NATS || $this->configuration->maxDeliver() === null || $stream === null) {
            return;
        }

        $config = self::configOf($stream);
        $cluster = is_array($stream->raw['cluster'] ?? null) ? $stream->raw['cluster'] : [];
        $retention = is_string($config['retention'] ?? null) ? $config['retention'] : 'limits';
        $replicas = is_int($config['num_replicas'] ?? null) ? $config['num_replicas'] : 1;
        $version = $this->client->serverInfo()?->version;

        $when = match (true) {
            $retention === 'interest' && isset($cluster['name']) => 'a stream with interest retention on a clustered server',
            $retention === 'interest' && (!self::serverVersionIsAtLeast($version, '2.12.9') || (self::serverVersionIsAtLeast($version, '2.14.0') && !self::serverVersionIsAtLeast($version, '2.14.1'))) => 'a stream with interest retention, at a restart of the server',
            $retention === 'workqueue' && $replicas > 1 && !self::serverVersionIsAtLeast($version, '2.12.5') => sprintf('a work-queue stream with %d replicas', $replicas),
            default => null,
        };
        if ($when === null) {
            return;
        }

        $this->logger?->warning(sprintf(
            'retry_handler=nats on stream "%s": NATS %s deletes a message that reached max_deliver from %s, so a message'
            . ' that keeps failing leaves nothing behind but the MAX_DELIVERIES advisory. Use retry_handler: symfony and'
            . ' retry_via: nak with a failure transport, or a stream with limits or work-queue retention (README, "Retry'
            . ' Handler Behavior").',
            $this->streamName,
            $version ?? 'of an unknown version',
            $when,
        ));
    }

    /** Whether $version, as a server reports it ("2.12.5", "v2.15.0-RC.1"), is $release or later; false when unknown. */
    private static function serverVersionIsAtLeast(?string $version, string $release): bool
    {
        $known = self::serverRelease($version);

        return $known !== null && version_compare($known, $release, '>=');
    }

    /**
     * The release a server's version names, as "major.minor.patch" ("v2.15.0-RC.1" is 2.15.0, "2.12" is 2.12.0), or
     * null when there is none to read. The one reading of a version for every check of one.
     */
    private static function serverRelease(?string $version): ?string
    {
        if ($version === null || preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?/', $version, $parts) !== 1) {
            return null;
        }

        return $parts[1] . '.' . $parts[2] . '.' . ($parts[3] ?? '0');
    }

    /**
     * The config of a stream or consumer info, or none when the server's answer has none.
     *
     * @return array<mixed>
     */
    private static function configOf(StreamInfo|ConsumerInfo $info): array
    {
        return is_array($info->raw['config'] ?? null) ? $info->raw['config'] : [];
    }

    /**
     * Refuses scheduled_messages on a stream with interest retention where no consumer has interest in the delayed
     * subjects, "{topic}.delayed.<uuid>". NATS stores nothing published to a subject that no consumer's filter matches
     * on such a stream, and a schedule exists only once its message is stored, so every delayed message was lost,
     * though its publish was acknowledged: with retry_via: copy (the default) a message that failed once ran once and
     * was gone, with no failure-transport copy (measured on nats-server 2.15.2, one server and three replicas). NATS
     * calls this intended (ADR-51, "Stream Retention Interaction"; nats-server issue #7408).
     *
     * Checked by setup(), with the stream it created or updated, and by a worker before its first pull, since a worker
     * sends Symfony's delayed retries; a process that only publishes is not checked. A stream with limits or
     * work-queue retention passes, and so does an interest stream with a consumer whose filter covers the delayed
     * subjects, such as one an operator added that nobody pulls from (ADR-51's pinning consumer). Once it passes it
     * is not checked again by this transport object. A stream or a consumer list it cannot read is logged once and
     * let through, as before.
     *
     * @throws TransportException When the stream would drop every delayed message.
     */
    private function assertTheStreamKeepsDelayedMessages(?StreamInfo $stream): void
    {
        if (!$this->configuration->isScheduledMessagesEnabled() || $this->delayedMessagesChecked) {
            return;
        }

        try {
            $stream ??= $this->awaitOnConnection(fn (): Future => $this->jetStream()->getStream($this->streamName));
            $config = self::configOf($stream);
            if (($config['retention'] ?? 'limits') !== 'interest') {
                $this->delayedMessagesChecked = true;

                return;
            }

            $consumers = $this->awaitOnConnection(fn (): Future => $this->jetStream()->listConsumers($this->streamName));
        } catch (\Throwable $failure) {
            $this->delayedMessagesChecked = true;
            $this->logger?->warning(sprintf(
                'scheduled_messages on stream "%s": the stream and its consumers could not be read (%s), so the transport'
                . ' could not check that the stream keeps delayed messages. A stream with interest retention keeps them'
                . ' only while a consumer has interest in "%s".',
                $this->streamName,
                $failure->getMessage(),
                $this->delayedSubjectPattern(),
            ));

            return;
        }

        // A subject send() could publish to: no filter names a fresh UUID.
        $delayedSubject = $this->topic . '.delayed.' . Uuid::v4();
        foreach ($consumers as $consumer) {
            if (self::consumerFiltersMatch($consumer, $delayedSubject)) {
                $this->delayedMessagesChecked = true;

                return;
            }
        }

        throw $this->delayedMessagesWouldBeLost();
    }

    /**
     * The check of {@see assertTheStreamKeepsDelayedMessages()} for setup(), made before setup() creates or updates the
     * stream: on the stream that exists, with its retention, which an update keeps, or on the configuration of the one
     * setup() would create, whose consumers do not exist yet. NATS turns allow_msg_schedules on with the write and
     * never turns it off again, so a refusal after the write, as up to the first form of 5.9.1, left the stream with
     * schedules on, and the first remedy the refusal names, turning scheduled_messages off, then failed with "message
     * schedules can not be disabled".
     */
    private function assertTheStreamToSetUpKeepsDelayedMessages(StreamConfiguration $streamConfiguration): void
    {
        if ($this->delayedMessagesChecked) {
            return;
        }

        $existing = $this->getExistingStream();
        if ($existing !== null) {
            $this->assertTheStreamKeepsDelayedMessages($existing);

            return;
        }

        if (($streamConfiguration->toArray()['retention'] ?? 'limits') === 'interest') {
            throw $this->delayedMessagesWouldBeLost();
        }

        $this->delayedMessagesChecked = true;
    }

    /**
     * The refusal of scheduled_messages on a stream with interest retention where no consumer has interest in the
     * delayed subjects, with the ways out.
     */
    private function delayedMessagesWouldBeLost(): TransportException
    {
        return new TransportException(sprintf(
            'scheduled_messages on stream "%s": the stream has interest retention and no consumer has interest in "%s",'
            . ' so NATS would store none of the delayed messages this transport publishes there, Symfony\'s delayed'
            . ' retries included, and each would be lost. Turn scheduled_messages off (with retry_via: nak, retries wait'
            . ' through a NAK instead), use a stream with limits or work-queue retention, or add a durable consumer'
            . ' filtered on "%s" that nobody pulls from, which keeps the delayed messages until they are due.',
            $this->streamName,
            $this->delayedSubjectPattern(),
            $this->delayedSubjectPattern(),
        ));
    }

    /**
     * Whether a consumer's filters take $subject: a consumer without a filter takes every subject of its stream.
     */
    private static function consumerFiltersMatch(ConsumerInfo $consumer, string $subject): bool
    {
        $config = self::configOf($consumer);
        $filters = [];
        if (is_string($config['filter_subject'] ?? null) && $config['filter_subject'] !== '') {
            $filters[] = $config['filter_subject'];
        }
        foreach (is_array($config['filter_subjects'] ?? null) ? $config['filter_subjects'] : [] as $filter) {
            if (is_string($filter) && $filter !== '') {
                $filters[] = $filter;
            }
        }

        if ($filters === []) {
            return true;
        }

        foreach ($filters as $filter) {
            if (self::subjectMatches($filter, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the NATS subject filter $filter matches $subject: "*" takes one token, a final ">" one or more.
     */
    private static function subjectMatches(string $filter, string $subject): bool
    {
        $filterTokens = explode('.', $filter);
        $subjectTokens = explode('.', $subject);
        foreach ($filterTokens as $position => $token) {
            if ($token === '>') {
                return count($subjectTokens) > $position;
            }

            if (!isset($subjectTokens[$position]) || ($token !== '*' && $token !== $subjectTokens[$position])) {
                return false;
            }
        }

        return count($filterTokens) === count($subjectTokens);
    }

    /**
     * The reason the connected server cannot schedule messages, or null when it can.
     *
     * NATS before 2.12 has no message schedules, and says nothing about it: it ignores the stream's
     * allow_msg_schedules setting, so setup() succeeded there, and it stores a message published with the
     * Nats-Schedule headers on its delayed subject and never delivers it (measured on nats-server 2.9.25, 2.10.29
     * and 2.11.17). The client reports a feature as unsupported only when the server rejects the setting, which
     * those servers do not, so the transport reads the version the server gave when the connection opened. A
     * server that gave none, or one this cannot read, is let through, for the server to decide.
     */
    private function schedulesUnsupportedByTheServer(): ?UnsupportedFeatureException
    {
        $version = $this->client->serverInfo()?->version;
        // A version the transport cannot read is let through: the server's own refusal of the option still stands.
        $release = self::serverRelease($version);
        if ($version === null || $release === null || version_compare($release, self::SCHEDULES_SINCE, '>=')) {
            return null;
        }

        return new UnsupportedFeatureException(
            'allow_msg_schedules',
            self::SCHEDULES_SINCE,
            $version,
            sprintf('Message schedules require NATS server %s+, but the connected server reports %s.', self::SCHEDULES_SINCE, $version),
        );
    }

    /**
     * Builds an actionable message for a version-gated feature the connected server is too old for.
     *
     * The only such feature this transport enables is `allow_msg_schedules` (via `scheduled_messages`),
     * so that case gets a tailored hint; anything else falls back to a generic message.
     */
    private function describeUnsupportedFeature(UnsupportedFeatureException $exception): string
    {
        $serverVersion = $exception->serverVersion ?? 'an older version';

        if ($this->configuration->isScheduledMessagesEnabled() && $exception->feature === 'allow_msg_schedules') {
            return sprintf(
                "The 'scheduled_messages' option requires NATS Server >= %s, but the connected server reports %s. Disable scheduled_messages or upgrade NATS.",
                $exception->requiredVersion,
                $serverVersion,
            );
        }

        return sprintf(
            "NATS Server feature '%s' requires version >= %s, but the connected server reports %s.",
            $exception->feature,
            $exception->requiredVersion,
            $serverVersion,
        );
    }

    /**
     * Builds the typed stream configuration managed by this transport.
     *
     * Used directly to create the stream (via {@see JetStreamContext::addStream()}) and, on the update
     * path, via {@see StreamConfiguration::toArray()} so the managed fields have a single definition.
     * {@see StreamConfiguration::maxAge()} performs the seconds→nanoseconds conversion internally.
     *
     * @param list<string> $subjects
     */
    private function buildManagedStreamConfiguration(array $subjects): StreamConfiguration
    {
        $streamConfiguration = (new StreamConfiguration($this->streamName))
            ->subjects(...$subjects)
            ->storage($this->configuration->streamStorage());

        if ($this->configuration->streamMaxAgeSeconds() > 0) {
            $streamConfiguration->maxAge($this->configuration->streamMaxAgeSeconds());
        }

        if ($this->configuration->streamMaxBytes() !== null) {
            $streamConfiguration->maxBytes($this->configuration->streamMaxBytes());
        }

        if ($this->configuration->streamMaxMessages() !== null) {
            $streamConfiguration->maxMessages($this->configuration->streamMaxMessages());
        }

        if ($this->configuration->streamMaxMessagesPerSubject() !== null) {
            $streamConfiguration->maxMsgsPerSubject($this->configuration->streamMaxMessagesPerSubject());
        }

        if ($this->configuration->streamMaxMessageSize() !== null) {
            $streamConfiguration->maxMsgSize($this->configuration->streamMaxMessageSize());
        }

        if ($this->configuration->streamMaxConsumers() !== null) {
            $streamConfiguration->maxConsumers($this->configuration->streamMaxConsumers());
        }

        // At least 1: NatsTransportConfiguration::streamReplicas() reads an unset option as one replica. The update
        // path keeps an existing stream's count when the option is unset ({@see buildUpdatedStreamConfiguration()}).
        $streamConfiguration->replicas($this->configuration->streamReplicas());

        // Retention is set only at creation - NATS rejects changing it on an existing stream, so the
        // update path ({@see buildUpdatedStreamConfiguration()}) preserves the server value instead.
        $retention = $this->configuration->streamRetention();
        if ($retention !== null) {
            $streamConfiguration->retention($retention);
        }

        $discard = $this->configuration->streamDiscard();
        if ($discard !== null) {
            $streamConfiguration->discard($discard);
        }

        if ($this->configuration->streamDuplicateWindowSeconds() !== null) {
            $streamConfiguration->duplicateWindow($this->configuration->streamDuplicateWindowSeconds());
        }

        $compression = $this->configuration->streamCompression();
        if ($compression !== null) {
            // The client models compression as a plain string field, so unwrap the local enum here.
            $streamConfiguration->compression($compression->value);
        }

        if ($this->configuration->streamDescription() !== null) {
            $streamConfiguration->description($this->configuration->streamDescription());
        }

        if ($this->configuration->streamDenyDelete() !== null) {
            $streamConfiguration->denyDelete($this->configuration->streamDenyDelete());
        }

        if ($this->configuration->streamDenyPurge() !== null) {
            $streamConfiguration->denyPurge($this->configuration->streamDenyPurge());
        }

        if ($this->configuration->streamAllowDirect() !== null) {
            $streamConfiguration->allowDirect($this->configuration->streamAllowDirect());
        }

        if ($this->configuration->streamAllowRollupHeaders() !== null) {
            $streamConfiguration->allowRollupHeaders($this->configuration->streamAllowRollupHeaders());
        }

        // Placement has no dedicated setter on the client's StreamConfiguration, so it goes through the
        // generic escape hatch. The block is built by the configuration object so cluster-only,
        // tags-only and combined placements all serialize the way the JetStream API expects.
        $placement = $this->configuration->streamPlacement();
        if ($placement !== null) {
            $streamConfiguration->set('placement', $placement);
        }

        if ($this->configuration->isScheduledMessagesEnabled()) {
            $streamConfiguration->set('allow_msg_schedules', true);
        }

        return $streamConfiguration;
    }

    /**
     * Returns the subjects this transport needs to be present on the stream.
     *
     * @return list<string>
     */
    private function buildDesiredSubjects(): array
    {
        $subjects = [$this->topic];
        if ($this->configuration->isScheduledMessagesEnabled()) {
            $subjects[] = $this->delayedSubjectPattern();
        }

        return $subjects;
    }

    /**
     * The transport-managed wildcard subject that holds scheduled messages until their delivery time.
     *
     * Defined once so the add ({@see buildDesiredSubjects()}) and drop ({@see buildUpdatedStreamConfiguration()})
     * sides of the scheduled-messages toggle can never disagree on the pattern. This is distinct from the
     * per-message publish subject `{topic}.delayed.{uuid}` built in {@see send()}.
     */
    private function delayedSubjectPattern(): string
    {
        return $this->topic . '.delayed.>';
    }

    /**
     * Builds the update payload for an existing stream while preserving server-side fields.
     *
     * @param array<string, mixed> $managedOptions
     * @param list<string>         $desiredSubjects
     * @return array<string, mixed>
     */
    private function buildUpdatedStreamConfiguration(
        StreamInfo $streamInfo,
        array $managedOptions,
        array $desiredSubjects,
    ): array {
        /** @var array<string, mixed> $serverConfiguration */
        $serverConfiguration = self::configOf($streamInfo);
        unset($serverConfiguration['name']);
        $serverConfiguration = $this->normalizeStreamConfigurationForUpdate($serverConfiguration);

        // StreamInfo::fromArray() reads its subjects from this same config entry.
        $serverSubjects = $this->normalizeSubjects($serverConfiguration['subjects'] ?? []);
        $mergedSubjects = $this->mergeSubjects($serverSubjects, $desiredSubjects);

        // When scheduled messages are disabled, actively drop the transport-managed '{topic}.delayed.>'
        // subject so a stream that previously had scheduling enabled does not keep an orphaned binding.
        // mergeSubjects() only ever adds, so without this an operator turning scheduled_messages off
        // could never remove it. Only this transport's own pattern is dropped; operator-added subjects
        // are preserved. Pairs with the allow_msg_schedules=false clearing below.
        if (!$this->configuration->isScheduledMessagesEnabled()) {
            $delayedSubject = $this->delayedSubjectPattern();
            $mergedSubjects = array_values(array_filter(
                $mergedSubjects,
                static fn (string $subject): bool => $subject !== $delayedSubject,
            ));
        }

        $updatedConfiguration = array_merge($serverConfiguration, $managedOptions, [
            'subjects' => $mergedSubjects,
        ]);

        // Placement is merged field by field instead of being taken from $managedOptions as a block,
        // see resolveUpdatedStreamPlacement(). The array_merge above replaced the live block whenever an
        // option is configured, so the merge starts again from the live value.
        $placement = $this->resolveUpdatedStreamPlacement($serverConfiguration['placement'] ?? null);
        if ($placement === null) {
            unset($updatedConfiguration['placement']);
        } else {
            $updatedConfiguration['placement'] = $placement;
        }

        // The transport authoritatively manages these retention limits. On update we always write
        // the value - including JetStream's "unlimited" sentinels (max_age 0, others -1) when the
        // option is unset - so a previously-configured limit is actually relaxed/cleared instead of
        // being preserved from the existing server configuration by the array_merge above. An unset
        // stream_max_age reads as 0 seconds, which is 0 nanoseconds.
        $updatedConfiguration['max_age'] = $this->configuration->streamMaxAgeSeconds() * self::SECONDS_TO_NANOSECONDS;
        $updatedConfiguration['max_bytes'] = $this->configuration->streamMaxBytes() ?? -1;
        $updatedConfiguration['max_msgs'] = $this->configuration->streamMaxMessages() ?? -1;
        $updatedConfiguration['max_msgs_per_subject'] = $this->configuration->streamMaxMessagesPerSubject() ?? -1;

        // NATS refuses a stream whose duplicate window is larger than a finite max age. The window is
        // usually inherited from the live server config (the transport only writes it when
        // stream_duplicate_window is set), so lowering stream_max_age on a stream that is on the
        // server's 2-minute default window produces exactly that rejection. The build-time validator
        // cannot see it, because it only compares options the caller actually supplied. Clamp the
        // window to the max age here, which is what the server itself does when a stream is created.
        $maxAgeNanoseconds = TypeCoercion::intValue($updatedConfiguration['max_age']);
        $duplicateWindowNanoseconds = TypeCoercion::intValue($updatedConfiguration['duplicate_window'] ?? 0);
        if ($maxAgeNanoseconds > 0 && $duplicateWindowNanoseconds > $maxAgeNanoseconds) {
            $updatedConfiguration['duplicate_window'] = $maxAgeNanoseconds;
        }

        // max_consumers and max_msg_size are deliberately NOT in the authoritative list above, because
        // this transport never wrote either field before these options existed. Both are therefore
        // fields an operator may have set out of band on a live stream, and resetting them would be a
        // behaviour change nobody asked for:
        //
        //  - max_consumers: NATS up to and including 2.11 refuses to change it at all, so writing the
        //    unlimited sentinel makes setup() fail permanently for anyone on the ^2.9 range this
        //    library supports whose stream has a consumer limit, and silently clears it on 2.12+.
        //  - max_msg_size: mutable on every supported version, so it fails silently rather than
        //    loudly - a stream capped at 1 MiB by an operator would be reset to unlimited on the next
        //    setup() run, with nothing in the output to say so.
        //  - placement (stream_placement_cluster / stream_placement_tags): pinned by operators in
        //    clustered deployments; dropping it would let the server migrate the stream's replicas
        //    onto arbitrary peers. Each option replaces only its own field of the live block, see
        //    resolveUpdatedStreamPlacement() above.
        //
        // Preservation works by echo, not by omission: a field left out of a STREAM.UPDATE payload is
        // read as the Go zero value and reset (verified against nats-server), so the values survive
        // only because getStream() returns them in $serverConfiguration and the array_merge above
        // carries them through.
        if (array_key_exists('storage', $serverConfiguration)) {
            $updatedConfiguration['storage'] = $serverConfiguration['storage'];
        }

        // Retention, like storage, is immutable on an existing stream: NATS rejects an update that
        // changes it. Preserve the server's value so update never attempts the change - a different
        // stream_retention in the DSN is silently ignored on an existing stream; recreate the stream to
        // change it. buildManagedStreamConfiguration() sets retention only at creation time.
        if (array_key_exists('retention', $serverConfiguration)) {
            $updatedConfiguration['retention'] = $serverConfiguration['retention'];
        }

        // deny_delete and deny_purge can be switched on but never off: NATS rejects an update that
        // cancels either one ("stream configuration update can not cancel deny message deletes" /
        // "... can not cancel deny purge"), on every supported version. Since the README shows
        // stream_deny_delete: false as a sample value, a stream that has the flag set would otherwise
        // fail every setup() from then on. Keep the server's value whenever it is already denied.
        // allow_direct and allow_rollup_hdrs were measured to be freely mutable in both directions, so
        // they stay with the array_merge above and remain changeable.
        foreach (['deny_delete', 'deny_purge'] as $oneWayFlag) {
            if (($serverConfiguration[$oneWayFlag] ?? false) === true) {
                $updatedConfiguration[$oneWayFlag] = true;
            }
        }

        // Preserve the existing replica count unless stream_replicas was explicitly configured.
        // The managed default (num_replicas = 1) would otherwise overwrite the server value via the
        // array_merge above and silently downscale a stream created with more replicas (e.g. in a
        // cluster), eliminating its high-availability/durability with no warning.
        if (!$this->configuration->hasExplicitStreamReplicas() && array_key_exists('num_replicas', $serverConfiguration)) {
            $updatedConfiguration['num_replicas'] = $serverConfiguration['num_replicas'];
        }

        // Authoritatively manage allow_msg_schedules: write true when scheduled_messages is enabled,
        // and explicitly false when it is disabled on a stream that previously had the flag set, so
        // turning the option off actually clears it instead of the array_merge preserving the server's
        // true. When the flag is off and the server never had it, leave it absent so the field is not
        // sent to a server too old to understand it (NATS < 2.12).
        if ($this->configuration->isScheduledMessagesEnabled()) {
            $updatedConfiguration['allow_msg_schedules'] = true;
        } elseif (array_key_exists('allow_msg_schedules', $serverConfiguration)) {
            $updatedConfiguration['allow_msg_schedules'] = false;
        }

        /** @var array<string, mixed> $updatedConfiguration */
        return $updatedConfiguration;
    }

    /**
     * Resolves the `placement` block a STREAM.UPDATE carries for an existing stream, or null to leave
     * the field out.
     *
     * The live block is the starting point: the server reads a missing field as the Go zero value and
     * clears it, and the two options describe at most two of its fields. Each configured option
     * replaces its own field and nothing else:
     *
     *  - `stream_placement_cluster` replaces `placement.cluster`; the live tags stay.
     *  - `stream_placement_tags` replaces `placement.tags` as a whole list; the live cluster stays.
     *  - an unset option leaves its field as the server reports it. So neither option can clear the
     *    other half of a placement an operator set in NATS; clearing a field is done in NATS directly.
     *
     * nats-server compares the stored block with the new one by structure and treats any difference as
     * a request to move the stream's replicas, a reordered tag list that names the same servers
     * included, and it refuses a move together with a replica change (err_code 10123). So when the
     * configured tags name the same set as the live tags, the live array is kept as the server stores
     * it: same order, same repeated entries, same case. The comparison is case-sensitive on purpose.
     * The server matches tags case-insensitively, but it switches its `unique_tag` check off through a
     * case-sensitive prefix test on the placement tag, so folding case here could silently keep a
     * bypass the operator just removed. A tag that differs only in case is a real change; once the
     * server stores the new spelling, the next setup() sees no difference.
     *
     * The block never gains an empty `tags` list or an empty `cluster`: the accessors return null for
     * those, and Go decodes `"tags": []` as an empty non-nil slice, which the server tells apart from
     * the absent field and reads as a move as well.
     *
     * @param mixed $livePlacement The `placement` entry of the live stream config, as getStream() reports it
     * @return array<string, mixed>|null
     */
    private function resolveUpdatedStreamPlacement(mixed $livePlacement): ?array
    {
        /** @var array<string, mixed> $placement */
        $placement = is_array($livePlacement) ? $livePlacement : [];

        $cluster = $this->configuration->streamPlacementCluster();
        if ($cluster !== null) {
            $placement['cluster'] = $cluster;
        }

        $tags = $this->configuration->streamPlacementTags();
        if ($tags !== null) {
            $liveTags = $placement['tags'] ?? null;
            if (!is_array($liveTags) || !$this->sameTagSet($liveTags, $tags)) {
                $placement['tags'] = $tags;
            }
        }

        return $placement === [] ? null : $placement;
    }

    /**
     * Whether a live tag list and a configured one name the same set of tags: order and repeated
     * entries aside, each tag compared exactly. See {@see resolveUpdatedStreamPlacement()} for why case
     * is not folded. A live list holding anything but strings never matches, so it gets replaced.
     *
     * @param array<mixed> $liveTags
     * @param list<string> $configuredTags
     */
    private function sameTagSet(array $liveTags, array $configuredTags): bool
    {
        $live = [];
        foreach ($liveTags as $tag) {
            if (!is_string($tag)) {
                return false;
            }

            $live[] = $tag;
        }

        // The configured list has no repeats, TypeCoercion::stringListValue() drops them; the live one may
        // carry any. sort() reindexes, so neither side needs array_values().
        $live = array_unique($live);
        sort($live, SORT_STRING);
        sort($configuredTags, SORT_STRING);

        return $live === $configuredTags;
    }

    /**
     * Normalizes stream config fields returned by getStream() before sending them back to updateStream().
     *
     * JetStream returns some map-like fields as empty arrays when unset. Those must be
     * re-encoded as JSON objects on update or the server rejects the payload.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function normalizeStreamConfigurationForUpdate(array $configuration): array
    {
        foreach (['consumer_limits', 'metadata'] as $objectLikeKey) {
            if (array_key_exists($objectLikeKey, $configuration) && $configuration[$objectLikeKey] === []) {
                $configuration[$objectLikeKey] = (object) [];
            }
        }

        return $configuration;
    }

    /**
     * @param mixed $subjects
     * @return list<string>
     */
    private function normalizeSubjects(mixed $subjects): array
    {
        if (!is_array($subjects)) {
            return [];
        }

        return array_values(array_filter($subjects, static fn (mixed $subject): bool => is_string($subject) && $subject !== ''));
    }

    /**
     * @param list<string> $existingSubjects
     * @param list<string> $desiredSubjects
     * @return list<string>
     */
    private function mergeSubjects(array $existingSubjects, array $desiredSubjects): array
    {
        $mergedSubjects = $existingSubjects;

        foreach ($desiredSubjects as $subject) {
            if (!in_array($subject, $mergedSubjects, true)) {
                $mergedSubjects[] = $subject;
            }
        }

        return $mergedSubjects;
    }
}
