<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;

/**
 * The transport with the JetStream context the test gives it, and no connection of its own, so that a test can
 * script the JetStream calls with a mock.
 */
final class JetStreamInjectedNatsTransport extends NatsTransport
{
    /** How often {@see pause()} may run in one test: whileTheClusterSettles() pauses at most 50 times in its 5 s. */
    private const MAX_PAUSES = 60;

    private int $pausesTaken = 0;

    /** @var array{NatsClient, JetStreamContext}|null What {@see connect()} hands the transport, when a test set it. */
    private ?array $dial = null;

    public function setJetStreamContext(JetStreamContext $jetStream): void
    {
        $this->jetStream = $jetStream;
    }

    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }

    /**
     * The client and JetStream context the transport gets when it connects, for a test of a transport that has not
     * connected yet: until then it has neither.
     */
    public function connectsTo(NatsClient $client, JetStreamContext $jetStream): void
    {
        $this->dial = [$client, $jetStream];
    }

    protected function connect(): void
    {
        // Keep unit tests isolated from live NATS.
        if ($this->dial !== null) {
            [$this->client, $this->jetStream] = $this->dial;
        }
    }

    /**
     * The real pause, failing the test once it ran more often than setup() ever pauses, so that a loop which never
     * ends fails within seconds instead of hanging the run.
     */
    protected function pause(float $seconds): void
    {
        if (++$this->pausesTaken > self::MAX_PAUSES) {
            throw new \LogicException(sprintf('pause() ran more than %d times: a loop that never ends.', self::MAX_PAUSES));
        }

        parent::pause($seconds);
    }
}
