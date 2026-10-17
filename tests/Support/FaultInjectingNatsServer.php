<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Amp\Cancellation;
use Amp\Future;
use IDCT\NATS\Transport\TransportClosedException;
use IDCT\NATS\Transport\TransportInterface;

use function Amp\async;
use function Amp\delay;

/**
 * The scripted server ({@see SubscriptionLimitNatsServer}) behind a connection that fails the way a real one does,
 * for tests that run the real client with reconnect on:
 *
 *  - goSilent(): from now on the live connection takes every write and delivers nothing, as a connection whose peer
 *    went away without closing it. A later connection delivers normally;
 *  - failNextWriteContaining(): the next write that contains the needle fails as a write to a dead socket does, once.
 *    The client reads such a failure as a lost connection and, with reconnect on, starts its recovery;
 *  - dropConnection(): the server closes the connection, so the client's read meets EOF;
 *  - holdNextDial(): the next dial completes only after the given time, so a recovery stays in flight that long;
 *  - refuseDials: every dial fails, so a recovery runs through its attempts and gives up.
 */
final class FaultInjectingNatsServer implements TransportInterface
{
    /** Whether every dial fails. */
    public bool $refuseDials = false;

    /** How many times the client dialled, the refused dials included; the server's sessions() counts the successful ones. */
    public int $dialAttempts = 0;

    /** The session whose frames are dropped, by the server's count (0 for the first connection), or null. */
    private ?int $silentSession = null;

    private ?string $failWriteContaining = null;

    private int $holdNextDialMs = 0;

    public function __construct(private readonly SubscriptionLimitNatsServer $server)
    {
    }

    public function connect(string $dsn, int $timeoutMs): Future
    {
        return async(function () use ($dsn, $timeoutMs): void {
            $this->dialAttempts++;

            if ($this->holdNextDialMs > 0) {
                $hold = $this->holdNextDialMs;
                $this->holdNextDialMs = 0;
                delay($hold / 1000);
            }

            if ($this->refuseDials) {
                throw new TransportClosedException('Connection refused');
            }

            $this->server->connect($dsn, $timeoutMs)->await();
        });
    }

    public function upgradeTls(): Future
    {
        return $this->server->upgradeTls();
    }

    public function write(string $bytes): Future
    {
        if ($this->failWriteContaining !== null && str_contains($bytes, $this->failWriteContaining)) {
            $this->failWriteContaining = null;

            return Future::error(new TransportClosedException('Broken pipe'));
        }

        return $this->server->write($bytes);
    }

    public function readLine(?Cancellation $cancellation = null): Future
    {
        return async(function () use ($cancellation): string {
            while (true) {
                $chunk = $this->server->readLine($cancellation)->await();
                if ($this->silentSession !== $this->server->sessions() - 1) {
                    return $chunk;
                }
                // The live connection is silent: what the server sent is dropped, and the read waits on.
            }
        });
    }

    public function close(): Future
    {
        return $this->server->close();
    }

    /** From now on the live connection delivers nothing (see the class). */
    public function goSilent(): void
    {
        $this->silentSession = $this->server->sessions() - 1;
    }

    /** The next write that contains $needle fails as a write to a dead socket does. One-shot. */
    public function failNextWriteContaining(string $needle): void
    {
        $this->failWriteContaining = $needle;
    }

    /** The next dial completes only after $milliseconds. One-shot. */
    public function holdNextDial(int $milliseconds): void
    {
        $this->holdNextDialMs = $milliseconds;
    }

    /** The server closes the connection: the client's read meets EOF. */
    public function dropConnection(): void
    {
        $this->server->close()->await();
    }
}
