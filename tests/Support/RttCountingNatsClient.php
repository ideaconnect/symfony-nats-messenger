<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

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

    public function rtt(): Future
    {
        $this->rtts++;

        return parent::rtt();
    }
}
