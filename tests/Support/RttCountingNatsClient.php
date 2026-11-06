<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Amp\Cancellation;
use Amp\Future;
use IDCT\NATS\Core\NatsClient;

/**
 * The real client, counting the PINGs the transport sends to check the connection: the transport is the only caller
 * of rtt(), and the client's own PINGs, the heartbeat's and the one behind each inbox's SUB, do not go through it.
 * NatsClient is final; tests/bootstrap.php enables BypassFinals, so it can be extended here.
 */
final class RttCountingNatsClient extends NatsClient
{
    public int $rtts = 0;

    /**
     * The budget of each request while set, in milliseconds, in place of the client's request timeout. So a test can
     * run one send out of its budget in a moment and give the operations around it all the time they could need: a
     * machine short of CPU can pause this process for longer than a short budget.
     */
    public ?int $requestBudgetMs = null;

    public function rtt(): Future
    {
        $this->rtts++;

        return parent::rtt();
    }

    public function request(string $subject, string $payload, ?int $timeoutMs = null, ?Cancellation $cancellation = null): Future
    {
        return parent::request($subject, $payload, $timeoutMs ?? $this->requestBudgetMs, $cancellation);
    }

    public function requestWithHeaders(string $subject, string $payload, array $headers, ?int $timeoutMs = null, ?Cancellation $cancellation = null): Future
    {
        return parent::requestWithHeaders($subject, $payload, $headers, $timeoutMs ?? $this->requestBudgetMs, $cancellation);
    }

    public function requestResendingOnReconnect(string $subject, string $payload, array $headers, \Closure $isInterimReply, ?int $timeoutMs = null, ?Cancellation $cancellation = null): Future
    {
        return parent::requestResendingOnReconnect($subject, $payload, $headers, $isInterimReply, $timeoutMs ?? $this->requestBudgetMs, $cancellation);
    }
}
