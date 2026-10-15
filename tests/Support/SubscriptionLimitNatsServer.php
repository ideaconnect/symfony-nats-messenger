<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use IDCT\NATS\Transport\TransportClosedException;
use IDCT\NATS\Transport\TransportInterface;
use Revolt\EventLoop;

use function Amp\async;

/**
 * A NATS server with one JetStream stream and one pull consumer on it, reduced to what the transport needs, with a
 * per-connection subscription limit, for tests that run the real client over it (#76).
 *
 * Like nats-server, each session (one per connect()) answers the client's frames in wire order:
 *  - a PING gets a PONG, after the answers to everything written ahead of it;
 *  - a SUB beyond {@see $limit} subscriptions gets "-ERR 'maximum subscriptions exceeded'" and is not held, and the
 *    connection stays open; an UNSUB frees its slot;
 *  - a publish to the stream's subject with a reply subject is stored and acknowledged on the subscription that
 *    covers the reply subject, the client's request inbox ("<prefix>.*"), and on nothing when none does;
 *  - a pull ("$JS.API.CONSUMER.MSG.NEXT.<stream>.<consumer>") on an inbox the session holds gets the stored messages
 *    not delivered yet, up to its batch, each with an ack subject, and status 408 when fewer were there, as a pull
 *    that found no more messages ends; a pull on an inbox the session does not hold gets nothing, since nothing is
 *    subscribed to deliver to;
 *  - a publish to an ack subject is recorded.
 * The stream's messages, the deliveries and the acknowledgements outlive a session, as on a real server.
 *
 * The test drives the rest:
 *  - {@see $rejectedSubWriteEndsAfterAnotherRead}: the writer of a refused SUB learns that its write is done only once
 *    another read has taken the -ERR, as on a socket under backpressure whose writer resumes late. While the pull
 *    waits for its write, the client's heartbeat is the only other reader;
 *  - limitErrorAndCloseOnNextWriteContaining(): the closing form. The server answers the next write that contains a
 *    needle with "-ERR 'maximum subscriptions exceeded'" alone, as nats-server does when an account's limit is lowered
 *    below what the connection holds, and closes the connection 20 ms later. serverClose() completes once it has.
 *
 * Reads block like an idle socket until something arrives, the session ends or the caller's cancellation fires, and
 * return everything that has arrived. A read of an ended session, and a write to it, fails as a closed socket does.
 */
final class SubscriptionLimitNatsServer implements TransportInterface
{
    public const LIMIT_ERR = "-ERR 'maximum subscriptions exceeded'\r\n";

    private const INFO = 'INFO {"server_id":"S1","server_name":"n1","version":"2.12.0","proto":1,"jetstream":true,"max_payload":1048576,"headers":true}' . "\r\n";

    /** How many subscriptions a session holds before it refuses a SUB. */
    public int $limit = PHP_INT_MAX;

    /** Whether the write of a refused SUB ends only once another read has taken the -ERR (see the class). */
    public bool $rejectedSubWriteEndsAfterAnotherRead = false;

    private readonly string $pullSubject;

    /** The index of the latest session: 0 for the first connect(), 1 for the next, ... */
    private int $session = -1;

    private bool $live = false;

    /** @var list<string> What the live session has sent and the client has not read yet. */
    private array $reads = [];

    /** @var array<int, string> The subject of each subscription the live session holds, by sid. */
    private array $subscriptions = [];

    /** The most subscriptions a session held at once. */
    private int $peakSubscriptions = 0;

    /** @var list<array{session: int, op: string, subject: string}> What the server received, in order. */
    private array $received = [];

    /** @var list<array{headers: ?string, payload: string}> The stream's messages, in order. */
    private array $stream = [];

    /** How many of the stream's messages the consumer has delivered. */
    private int $delivered = 0;

    /** @var list<array{subject: string, payload: string}> The acknowledgements, in order. */
    private array $acks = [];

    /** The needle of the write the server answers with the limit -ERR before it closes the connection. */
    private ?string $closeOn = null;

    /** @var DeferredFuture<null>|null Completed once the server closed the connection behind the -ERR. */
    private ?DeferredFuture $serverClose = null;

    /** @var DeferredFuture<null>|null The write of a refused SUB, held until a read takes the -ERR. */
    private ?DeferredFuture $heldWrite = null;

    /** @var DeferredFuture<null> Completed (and replaced) whenever parked reads must look again. */
    private DeferredFuture $wake;

    public function __construct(
        private readonly string $streamName,
        private readonly string $topic,
        string $consumer,
    ) {
        $this->pullSubject = '$JS.API.CONSUMER.MSG.NEXT.' . $streamName . '.' . $consumer;
        $this->wake = new DeferredFuture();
    }

    public function connect(string $dsn, int $timeoutMs): Future
    {
        $this->session++;
        $this->live = true;
        $this->subscriptions = [];
        $this->reads = [self::INFO];
        $this->wakeReaders();

        return Future::complete();
    }

    public function upgradeTls(): Future
    {
        return Future::complete();
    }

    public function write(string $bytes): Future
    {
        if (!$this->live) {
            return Future::error(new TransportClosedException('The connection is gone'));
        }

        $frames = self::parseClientFrames($bytes);
        if ($this->closeOn !== null && str_contains($bytes, $this->closeOn)) {
            $this->closeOn = null;
            foreach ($frames as $frame) {
                $this->record($frame['op'], $frame['args'][0] ?? '');
            }
            $this->send(self::LIMIT_ERR);
            $serverClose = $this->serverClose;
            EventLoop::delay(0.02, function () use ($serverClose): void {
                $this->endSession();
                $serverClose?->complete();
            });

            return Future::complete();
        }

        $subscribing = false;
        $refused = false;
        foreach ($frames as $frame) {
            $subscribing = $subscribing || $frame['op'] === 'SUB';
        }

        foreach ($frames as $frame) {
            $args = $frame['args'];
            switch ($frame['op']) {
                case 'PING':
                    // The PING the client writes behind a SUB, in the same write, is its own: not recorded.
                    if (!$subscribing) {
                        $this->record('PING', '');
                    }
                    $this->send("PONG\r\n");
                    break;
                case 'SUB':
                    $this->record('SUB', $args[0]);
                    if (count($this->subscriptions) >= $this->limit) {
                        $refused = true;
                        $this->record('REFUSED', $args[0]);
                        $this->send(self::LIMIT_ERR);
                        break;
                    }
                    $this->subscriptions[(int) $args[count($args) - 1]] = $args[0];
                    $this->peakSubscriptions = max($this->peakSubscriptions, count($this->subscriptions));
                    break;
                case 'UNSUB':
                    $this->record('UNSUB', $args[0]);
                    unset($this->subscriptions[(int) $args[0]]);
                    break;
                case 'PUB':
                case 'HPUB':
                    $this->publish($frame);
                    break;
            }
        }

        if ($refused && $this->rejectedSubWriteEndsAfterAnotherRead) {
            $this->heldWrite = new DeferredFuture();

            return $this->heldWrite->getFuture();
        }

        return Future::complete();
    }

    public function readLine(?Cancellation $cancellation = null): Future
    {
        $session = $this->session;

        return async(function () use ($cancellation, $session): string {
            while (true) {
                if (!$this->live || $this->session !== $session) {
                    throw new TransportClosedException('Socket closed by peer (EOF)');
                }

                if ($this->reads !== []) {
                    $chunk = implode('', $this->reads);
                    $this->reads = [];
                    if ($this->heldWrite !== null && str_contains($chunk, '-ERR ')) {
                        $held = $this->heldWrite;
                        $this->heldWrite = null;
                        // Ended on a later tick, once the read that took the -ERR has handled it.
                        EventLoop::delay(0.01, static function () use ($held): void {
                            $held->complete();
                        });
                    }

                    return $chunk;
                }

                // An idle socket keeps the event loop running; the client's read deadlines do not.
                $keepAlive = EventLoop::delay(30, static function (): void {});
                try {
                    $this->wake->getFuture()->await($cancellation);
                } finally {
                    EventLoop::cancel($keepAlive);
                }
            }
        });
    }

    public function close(): Future
    {
        $this->endSession();

        return Future::complete();
    }

    /**
     * The closing form: the next write that contains $needle is answered with the limit -ERR alone, and the server
     * closes the connection 20 ms later. One-shot.
     */
    public function limitErrorAndCloseOnNextWriteContaining(string $needle): void
    {
        $this->closeOn = $needle;
        $this->serverClose = new DeferredFuture();
    }

    /**
     * Completes once the server has closed the connection behind the -ERR (see
     * {@see limitErrorAndCloseOnNextWriteContaining()}).
     *
     * @return Future<null>
     */
    public function serverClose(): Future
    {
        if ($this->serverClose === null) {
            throw new \LogicException('No close was armed');
        }

        return $this->serverClose->getFuture();
    }

    /** How many times the client connected. */
    public function sessions(): int
    {
        return $this->session + 1;
    }

    public function peakSubscriptions(): int
    {
        return $this->peakSubscriptions;
    }

    /**
     * What the server received, in order, optionally in one session: "PING" for a PING written on its own, "SUB
     * <subject>", "REFUSED <subject>" right after a SUB it refused, "UNSUB <sid>", "PUBLISH <subject>", "PULL <inbox>"
     * and "ACK <payload>". A subject that is an inbox is shortened to its prefix: "_INBOX.JS.FETCH" for a pull's, "_INBOX"
     * for the request inbox.
     *
     * @return list<string>
     */
    public function received(?int $session = null): array
    {
        $lines = [];
        foreach ($this->received as $entry) {
            if ($session !== null && $entry['session'] !== $session) {
                continue;
            }

            $lines[] = trim($entry['op'] . ' ' . self::shorten($entry['subject']));
        }

        return $lines;
    }

    /**
     * The inbox of every pull the server received, in order.
     *
     * @return list<string>
     */
    public function pullInboxes(): array
    {
        $inboxes = [];
        foreach ($this->received as $entry) {
            if ($entry['op'] === 'PULL') {
                $inboxes[] = $entry['subject'];
            }
        }

        return $inboxes;
    }

    /**
     * @return list<array{subject: string, payload: string}>
     */
    public function acks(): array
    {
        return $this->acks;
    }

    /**
     * @param array{op: string, line: string, args: list<string>, payload: string} $frame
     */
    private function publish(array $frame): void
    {
        $args = $frame['args'];
        $subject = $args[0];
        $withReply = count($args) === ($frame['op'] === 'PUB' ? 3 : 4);
        $replyTo = $withReply ? $args[1] : null;
        $headerLength = $frame['op'] === 'HPUB' ? (int) $args[count($args) - 2] : 0;
        $headers = $frame['op'] === 'HPUB' ? substr($frame['payload'], 0, $headerLength) : null;
        $body = substr($frame['payload'], $headerLength);

        if (str_starts_with($subject, '$JS.ACK.')) {
            $this->record('ACK', $body);
            $this->acks[] = ['subject' => $subject, 'payload' => $body];
            if ($replyTo !== null) {
                $this->reply($replyTo, '');
            }

            return;
        }

        if ($subject === $this->pullSubject && $replyTo !== null) {
            $this->record('PULL', $replyTo);
            $this->pull($replyTo, $body);

            return;
        }

        $this->record('PUBLISH', $subject);
        if ($subject === $this->topic && $replyTo !== null) {
            $this->stream[] = ['headers' => $headers, 'payload' => $body];
            $this->reply($replyTo, (string) json_encode(['stream' => $this->streamName, 'seq' => count($this->stream)]));
        }
    }

    private function pull(string $inbox, string $request): void
    {
        $sid = array_search($inbox, $this->subscriptions, true);
        if ($sid === false) {
            return;
        }

        $decoded = json_decode($request, true);
        $batch = is_array($decoded) && is_int($decoded['batch'] ?? null) ? $decoded['batch'] : 1;
        $sent = 0;
        while ($sent < $batch && $this->delivered < count($this->stream)) {
            $message = $this->stream[$this->delivered];
            $this->delivered++;
            $sent++;
            $ackSubject = sprintf(
                '$JS.ACK.%s.%s.1.%d.%d.%d.%d',
                $this->streamName,
                substr($this->pullSubject, strrpos($this->pullSubject, '.') + 1),
                $this->delivered,
                $this->delivered,
                1_700_000_000_000_000_000 + $this->delivered,
                count($this->stream) - $this->delivered,
            );
            if ($message['headers'] === null) {
                $this->send(sprintf("MSG %s %d %s %d\r\n%s\r\n", $inbox, $sid, $ackSubject, strlen($message['payload']), $message['payload']));
            } else {
                $total = strlen($message['headers']) + strlen($message['payload']);
                $this->send(sprintf("HMSG %s %d %s %d %d\r\n%s%s\r\n", $inbox, $sid, $ackSubject, strlen($message['headers']), $total, $message['headers'], $message['payload']));
            }
        }

        if ($sent < $batch) {
            $status = "NATS/1.0 408 Request Timeout\r\n\r\n";
            $this->send(sprintf("HMSG %s %d %d %d\r\n%s\r\n", $inbox, $sid, strlen($status), strlen($status), $status));
        }
    }

    /** A reply on the subscription that covers $replyTo: the exact subject, or a "<base>.*" wildcard. */
    private function reply(string $replyTo, string $payload): void
    {
        foreach ($this->subscriptions as $sid => $subject) {
            $base = substr($subject, 0, -1);
            $covered = $subject === $replyTo
                || (str_ends_with($subject, '.*') && str_starts_with($replyTo, $base) && !str_contains(substr($replyTo, strlen($base)), '.'));
            if ($covered) {
                $this->send(sprintf("MSG %s %d %d\r\n%s\r\n", $replyTo, $sid, strlen($payload), $payload));

                return;
            }
        }
    }

    private function record(string $op, string $subject): void
    {
        $this->received[] = ['session' => $this->session, 'op' => $op, 'subject' => $subject];
    }

    private static function shorten(string $subject): string
    {
        if (str_starts_with($subject, '_INBOX.JS.FETCH.')) {
            return '_INBOX.JS.FETCH';
        }

        return str_starts_with($subject, '_INBOX.') ? '_INBOX' : $subject;
    }

    private function send(string $chunk): void
    {
        $this->reads[] = $chunk;
        $this->wakeReaders();
    }

    private function endSession(): void
    {
        $this->live = false;
        $this->reads = [];
        $this->subscriptions = [];
        $this->wakeReaders();
    }

    private function wakeReaders(): void
    {
        $wake = $this->wake;
        $this->wake = new DeferredFuture();
        $wake->complete();
    }

    /**
     * Splits client bytes into frames: the control line, its operation and arguments, and for PUB and HPUB the payload
     * bytes (for HPUB, headers and body together).
     *
     * @return list<array{op: string, line: string, args: list<string>, payload: string}>
     */
    private static function parseClientFrames(string $bytes): array
    {
        $frames = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $end = strpos($bytes, "\r\n", $offset);
            if ($end === false) {
                break;
            }

            $line = substr($bytes, $offset, $end - $offset);
            $offset = $end + 2;
            $parts = explode(' ', $line);
            $op = strtoupper($parts[0]);
            $args = array_values(array_slice($parts, 1));

            $payload = '';
            if (($op === 'PUB' || $op === 'HPUB') && $args !== []) {
                $size = (int) $args[count($args) - 1];
                $payload = substr($bytes, $offset, $size);
                $offset += $size + 2;
            }

            $frames[] = ['op' => $op, 'line' => $line, 'args' => $args, 'payload' => $payload];
        }

        return $frames;
    }
}
