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
use IDCT\NATS\JetStream\Enum\ReplayPolicy;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NATS\JetStream\Models\StreamInfo;
use IDCT\NATS\JetStream\Schedule;
use IDCT\NatsMessenger\Options\NatsTransportConfiguration;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use IDCT\NatsMessenger\Options\RetryHandler;
use IDCT\NatsMessenger\Serializer\IgbinarySerializer;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use LogicException;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
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
     * Creates a transport instance from DSN/options and optional serializer override.
     *
     * Parses the DSN and options via {@see NatsTransportConfigurationBuilder}, sets up
     * the serializer (defaults to {@see IgbinarySerializer} if the extension is available),
     * and stores the resolved configuration. No connection is made at construction time.
     *
     * @param string                 $dsn        NATS JetStream DSN (e.g. nats-jetstream://host:4222/stream/topic)
     * @param array<string, mixed>   $options    Transport option overrides (take precedence over DSN query params)
     * @param SerializerInterface|null $serializer Custom serializer; when null, defaults to igbinary
     */
    public function __construct(#[\SensitiveParameter] string $dsn, array $options, ?SerializerInterface $serializer = null)
    {
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

        $configuration = (new NatsTransportConfigurationBuilder())->build($dsn, $options);
        $this->configuration = $configuration;
        $this->topic = $configuration->topic;
        $this->streamName = $configuration->streamName;
        $this->client = $configuration->client;
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
     * published ({@see isRetryNatsRedeliversItself()}).
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
            return $envelope;
        }

        $this->autoSetupIfEnabled();

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

        $delayMs = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        if ($delayMs > 0 && $this->configuration->isScheduledMessagesEnabled()) {
            $deliverAt = new \DateTimeImmutable('+' . $delayMs . ' milliseconds');
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
            $normalizedHeaders[(string) $name] = TypeCoercion::stringValue($value);
        }

        // A single JetStream publish path for both plain and header-carrying (incl. scheduled)
        // messages. JetStreamContext::publish() retries transient 503 "no responders" and parses
        // the PubAck, throwing JetStreamException on an empty/malformed reply or a reported error -
        // so the publish fails closed instead of silently accepting an invalid acknowledgement.
        $messageId = $deduplicationStamp === null ? null : $this->natsMessageId($deduplicationStamp, $envelope);
        try {
            $this->awaitOnConnection($this->jetStream()->publish($topic, $payload, $normalizedHeaders, msgId: $messageId));
        } catch (JetStreamException $reply) {
            if (!$this->reprovisionedForThePublishAfterReconnect($reply)) {
                throw $reply;
            }

            // The same frame once more: the same subject (the scheduled one of a delayed message), payload, headers
            // and Nats-Msg-Id. A second pass through send() would mint another id and, for a delayed message,
            // another schedule.
            $this->awaitOnConnection($this->jetStream()->publish($topic, $payload, $normalizedHeaders, msgId: $messageId));
        }

        return $envelope;
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

        $this->autoSetupDone = false;
        $this->autoSetupIfEnabled();

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
     * NATS handles redelivery (retry_handler=nats).
     *
     * Symfony's retry listener runs for every transport with a retry strategy, and FrameworkBundle gives each
     * one a strategy by default. It re-sends the failed message with a retry count, and the worker then calls
     * reject(), which in nats mode NAKs the original so NATS redelivers it. Publishing the copy as well
     * retried the same failure twice, and every copy did the same again, multiplying the deliveries: one
     * message that kept failing ran its handler 120 times with max_deliver 3 (#47). So in nats mode the copy
     * is not published, which means Symfony's retry strategy (max_retries, delay, multiplier) is ignored and
     * NATS redelivers alone, under nak_delay, backoff and max_deliver.
     *
     * A copy for the failure transport carries a retry count of 0 and is still sent, as is anything that was
     * not received in this process.
     */
    private function isRetryNatsRedeliversItself(Envelope $envelope): bool
    {
        return $this->configuration->retryHandler() === RetryHandler::NATS
            && $envelope->last(ReceivedStamp::class) !== null
            && RedeliveryStamp::getRetryCountFromEnvelope($envelope) > 0;
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
     * `1`, is not passed to the serializer, whose decode() takes string names. On deserialization
     * failure the message is rejected via {@see handleFailedDelivery()} before the exception
     * propagates.
     *
     * With auto_setup enabled, a 404, 409 or 503 (see {@see recoverFromFetchFailure()} for why
     * all three) first triggers one re-provisioning attempt and a second pull.
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

            $headers = ($message->rawHeaders !== null && $message->rawHeaders !== '')
                ? self::stringNamedHeaders(NatsHeaders::fromWireBlock($message->rawHeaders))
                : [];

            try {
                $decoded = $this->serializer->decode([
                    'body' => $message->payload,
                    'headers' => $headers,
                ]);
            } catch (\Throwable $e) {
                // Reject the undecodable message, but never let a NAK/TERM transport failure (e.g. a
                // dropped connection while acknowledging) replace the decode exception: that original
                // error is the root cause an operator needs, so it stays the one that propagates.
                try {
                    $this->handleFailedDelivery($replyTo);
                } catch (\Throwable) {
                    // Intentionally swallowed - $e is rethrown below as the primary failure.
                }

                throw $e;
            }

            yield $decoded->with(new TransportMessageIdStamp($replyTo));
        }
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
            $this->awaitOnConnection($this->jetStream()->nakWithDelay($message, $nakDelayMs));

            return;
        }

        $this->awaitOnConnection($this->jetStream()->nak($message));
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
        $this->awaitOnConnection($this->jetStream()->term($this->buildAckMessage($id)));
    }

    /**
     * Acknowledges successful handling of a received envelope.
     *
     * Extracts the JetStream delivery token from the envelope's TransportMessageIdStamp
     * and sends an ACK to JetStream so the message won't be redelivered. When the
     * {@see NatsTransportConfiguration::isAckSyncEnabled()} option is on, the ACK waits for
     * server confirmation (double-ack) so a dropped ACK cannot silently cause redelivery.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function ack(Envelope $envelope): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());
        $message = $this->buildAckMessage($id);

        if ($this->configuration->isAckSyncEnabled()) {
            $this->awaitOnConnection($this->jetStream()->ackSync($message));

            return;
        }

        $this->awaitOnConnection($this->jetStream()->ack($message));
    }

    /**
     * Rejects a received envelope according to configured retry strategy.
     *
     * Delegates to {@see handleFailedDelivery()}: sends TERM when using Symfony retry
     * handling or NAK when using NATS-native redelivery.
     *
     * @throws LogicException If the envelope lacks a TransportMessageIdStamp
     */
    public function reject(Envelope $envelope): void
    {
        $id = TypeCoercion::stringValue($this->findReceivedStamp($envelope)->getId());
        $this->handleFailedDelivery($id);
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
     * When NATS cannot be reached it returns 0, the same as for an empty queue, so `messenger:stats`
     * shows 0 during an outage. It gives up after a single connection attempt.
     */
    public function getMessageCount(): int
    {
        try {
            $consumerInfo = $this->awaitOnConnection($this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer()));
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
                $streamInfo = $this->awaitOnConnection($this->jetStream()->getStream($this->streamName));
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

            try {
                $this->awaitOnConnection($this->jetStream()->addStream($streamConfiguration));
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
                $this->awaitOnConnection($this->jetStream()->updateStream($this->streamName, $updatedConfiguration));
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

            $replayPolicy = $this->resolveReplayPolicy();
            if ($replayPolicy !== null) {
                $consumerConfiguration->replayPolicy($replayPolicy);
            }

            $consumerInfo = $this->awaitOnConnection($this->jetStream()->addConsumer($this->streamName, $consumerConfiguration));
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
     */
    private function autoSetupIfEnabled(): void
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

        $this->setup();
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
     * may not be there.
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

        if ($this->pullEndedByTheServer($exception)) {
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
                // ended itself is as empty here as on the first try.
                if ($retryException->getCode() === 404 || $retryException->getCode() === 408 || $this->pullEndedByTheServer($retryException)) {
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
            return $this->awaitOnConnection($jetStream->fetchBatch(
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
     * @template T
     * @param Future<T> $call
     * @return T
     */
    private function awaitOnConnection(Future $call): mixed
    {
        try {
            return $call->await();
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
        $config = is_array($consumerInfo->raw['config'] ?? null) ? $consumerInfo->raw['config'] : [];

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
        $config = is_array($existingConsumer->raw['config'] ?? null) ? $existingConsumer->raw['config'] : [];
        $currentPolicy = $config['replay_policy'] ?? null;

        // A consumer reporting no replay policy is on the server default, so leaving the field out
        // matches it and avoids sending a value an older server might not understand.
        return $currentPolicy === null ? null : ReplayPolicy::tryFrom(TypeCoercion::stringValue($currentPolicy));
    }

    /**
     * Returns the existing durable consumer, or null when it does not exist.
     *
     * Mirrors {@see getExistingStream()}: a 404 means the consumer is absent, anything else propagates.
     */
    private function getExistingConsumer(): ?ConsumerInfo
    {
        try {
            return $this->awaitOnConnection($this->jetStream()->getConsumer($this->streamName, $this->configuration->consumer()));
        } catch (JetStreamException $exception) {
            if ($exception->getCode() === 404) {
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
            return $this->awaitOnConnection($this->jetStream()->getStream($this->streamName));
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

        if ($this->configuration->streamReplicas() > 0) {
            $streamConfiguration->replicas($this->configuration->streamReplicas());
        }

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
        $serverConfiguration = is_array($streamInfo->raw['config'] ?? null) ? $streamInfo->raw['config'] : [];
        unset($serverConfiguration['name']);
        $serverConfiguration = $this->normalizeStreamConfigurationForUpdate($serverConfiguration);

        $serverSubjects = $this->normalizeSubjects($serverConfiguration['subjects'] ?? $streamInfo->subjects);
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
        // being preserved from the existing server configuration by the array_merge above.
        $updatedConfiguration['max_age'] = $this->configuration->streamMaxAgeSeconds() > 0
            ? $this->configuration->streamMaxAgeSeconds() * self::SECONDS_TO_NANOSECONDS
            : 0;
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
