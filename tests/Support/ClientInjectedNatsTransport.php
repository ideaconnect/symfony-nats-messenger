<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NATS\Core\NatsClient;
use IDCT\NatsMessenger\NatsTransport;

/**
 * The transport with the client the test gives it, so that a test can run the real client over a scripted server.
 */
final class ClientInjectedNatsTransport extends NatsTransport
{
    /** How often {@see pause()} may run in one test: whileTheClusterSettles() pauses at most 50 times in its 5 s. */
    private const MAX_PAUSES = 60;

    /** Seconds added to the transport's clock, so that a test can take a connection past ping_after_idle without waiting. */
    public float $clockOffset = 0.0;

    private int $pausesTaken = 0;

    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }

    protected function monotonicSeconds(): float
    {
        return parent::monotonicSeconds() + $this->clockOffset;
    }

    /**
     * The real pause, failing the test once it ran more often than setup() ever pauses, so that a loop which never
     * ends fails within seconds instead of hanging the run (as the tests of an auto_setup pull reach setup() here).
     */
    protected function pause(float $seconds): void
    {
        if (++$this->pausesTaken > self::MAX_PAUSES) {
            throw new \LogicException(sprintf('pause() ran more than %d times: a loop that never ends.', self::MAX_PAUSES));
        }

        parent::pause($seconds);
    }
}
