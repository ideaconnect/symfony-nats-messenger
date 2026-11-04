<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Amp\Cancellation;
use Amp\Future;
use IDCT\NATS\Transport\PeerCloseAwareTransportInterface;
use IDCT\NATS\Transport\TransportClosedException;

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
 *  - dropConnectionAfterNextWriteContaining(): the next write that contains the needle goes out, and the server goes
 *    down with it before it answers, as a stream's leader that stops right after it took a publish;
 *  - closeUnread(): the server closes the connection while the client is not reading: the next read meets EOF, and
 *    a write before it still succeeds and is lost. Since client 2.28.0 the client asks peerClosed() before a
 *    publish or request write, which sees such a close on a plain socket; closeUnread(behindUnreadBytes: true) is a
 *    close no peek can see, as over TLS, where the server's close_notify waits unread ahead of it;
 *  - holdNextDial(): the next dial completes only after the given time, so a recovery stays in flight that long;
 *  - refuseDials: every dial fails, so a recovery runs through its attempts and gives up.
 */
final class FaultInjectingNatsServer implements PeerCloseAwareTransportInterface
{
    /** Whether every dial fails. */
    public bool $refuseDials = false;

    /** How many times the client dialled, the refused dials included; the server's sessions() counts the successful ones. */
    public int $dialAttempts = 0;

    /** The session whose frames are dropped, by the server's count (0 for the first connection), or null. */
    private ?int $silentSession = null;

    private ?string $failWriteContaining = null;

    private ?string $dropAfterWriteContaining = null;

    private int $holdNextDialMs = 0;

    /** The session closeUnread() closed, by the server's count, or null. */
    private ?int $closedUnreadSession = null;

    /** Whether that close sits behind bytes not read yet, where peerClosed() cannot see it. */
    private bool $closedBehindUnreadBytes = false;

    /** @var list<string> */
    private array $lostWrites = [];

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
        if ($this->closedUnreadSession !== null && $this->closedUnreadSession === $this->server->sessions() - 1) {
            $this->lostWrites[] = $bytes;

            return Future::complete();
        }

        if ($this->failWriteContaining !== null && str_contains($bytes, $this->failWriteContaining)) {
            $this->failWriteContaining = null;

            return Future::error(new TransportClosedException('Broken pipe'));
        }

        if ($this->dropAfterWriteContaining !== null && str_contains($bytes, $this->dropAfterWriteContaining)) {
            $this->dropAfterWriteContaining = null;
            // The write went out; the server that took it goes down before it answers, closing the connection.
            $this->lostWrites[] = $bytes;

            return $this->server->close();
        }

        return $this->server->write($bytes);
    }

    public function peerClosed(): bool
    {
        return $this->closedUnreadSession !== null
            && $this->closedUnreadSession === $this->server->sessions() - 1
            && !$this->closedBehindUnreadBytes;
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

    /**
     * The next write that contains $needle goes out, and the server closes the connection before it answers it, as a
     * stream's leader that stops right after it took a publish. One-shot.
     */
    public function dropConnectionAfterNextWriteContaining(string $needle): void
    {
        $this->dropAfterWriteContaining = $needle;
    }

    /**
     * The server closes the connection while the client is not reading, as a server that shuts down while a handler
     * runs does: the close waits unread, a write on that connection still succeeds, as a write after the peer's FIN
     * does, and its bytes reach no server; the next read meets EOF. peerClosed() reports the close, as the peek of
     * the client's socket transport does on a plain socket, unless $behindUnreadBytes: then it sits behind bytes not
     * read yet, as behind the close_notify of a TLS server, where no peek can tell it from data.
     */
    public function closeUnread(bool $behindUnreadBytes = false): void
    {
        $this->server->close()->await();
        $this->closedUnreadSession = $this->server->sessions() - 1;
        $this->closedBehindUnreadBytes = $behindUnreadBytes;
    }

    /**
     * What the client wrote into the connection closeUnread() closed, which no server read.
     *
     * @return list<string>
     */
    public function lostWrites(): array
    {
        return $this->lostWrites;
    }
}
