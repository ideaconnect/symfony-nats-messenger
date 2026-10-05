<?php

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Transport\TransportInterface;
use IDCT\NatsMessenger\NatsTransport;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Amp\async;

/**
 * The server a client's socket talks to, reduced to what a pull needs: it answers the handshake and every
 * PING, and a pull only when told to, with status 408, the way a server ends a pull that found no messages.
 * A pull it leaves unanswered runs into the client's own deadline, as on a half-open connection.
 */
final class PullAnsweringServer implements TransportInterface
{
    private const PULL_PREFIX = '$JS.API.CONSUMER.MSG.NEXT.';

    /** @var list<string> What the client sent: PING for a PING, PULL for a pull request, in order. */
    public array $received = [];

    /** @var list<bool> Whether to answer each next pull, in order. A pull past the end is left unanswered. */
    public array $answerPulls = [];

    /** @var list<string> Chunks for the client to read. */
    private array $chunks = [
        'INFO {"server_id":"S1","server_name":"n1","version":"2.12.0","proto":1,"jetstream":true,"max_payload":1048576,"headers":true}' . "\r\n",
    ];

    /** @var array<string, string> The sid of each subject the client subscribed to. */
    private array $sids = [];

    /** @var DeferredFuture<null>|null Completed when a chunk arrives for a read that found none. */
    private ?DeferredFuture $chunkArrived = null;

    public function connect(string $dsn, int $timeoutMs): Future
    {
        return Future::complete();
    }

    public function upgradeTls(): Future
    {
        return Future::complete();
    }

    public function write(string $bytes): Future
    {
        foreach (explode("\r\n", $bytes) as $line) {
            $words = explode(' ', $line);
            if ($line === 'PING') {
                $this->received[] = 'PING';
                $this->send("PONG\r\n");
            } elseif ($words[0] === 'SUB' && count($words) === 3) {
                $this->sids[$words[1]] = $words[2];
            } elseif ($words[0] === 'PUB' && count($words) === 4 && str_starts_with($words[1], self::PULL_PREFIX)) {
                $this->received[] = 'PULL';
                if (array_shift($this->answerPulls) === true) {
                    $status = "NATS/1.0 408 Request Timeout\r\n\r\n";
                    $length = strlen($status);
                    $this->send("HMSG {$words[2]} {$this->sids[$words[2]]} {$length} {$length}\r\n{$status}\r\n");
                }
            }
        }

        return Future::complete();
    }

    public function readLine(?Cancellation $cancellation = null): Future
    {
        return async(function () use ($cancellation): string {
            while ($this->chunks === []) {
                $this->chunkArrived ??= new DeferredFuture();
                // A socket that waits for data keeps the event loop running; the client's read deadlines do not.
                $keepAlive = EventLoop::delay(60, static function (): void {});
                try {
                    $this->chunkArrived->getFuture()->await($cancellation);
                } finally {
                    EventLoop::cancel($keepAlive);
                }
            }

            return array_shift($this->chunks);
        });
    }

    public function close(): Future
    {
        return Future::complete();
    }

    private function send(string $chunk): void
    {
        $this->chunks[] = $chunk;
        $arrived = $this->chunkArrived;
        $this->chunkArrived = null;
        $arrived?->complete();
    }
}

/**
 * The transport with the client the test gives it.
 */
final class ClientInjectedNatsTransport extends NatsTransport
{
    public function setClient(NatsClient $client): void
    {
        $this->client = $client;
    }
}

/**
 * The client reports a pull that got no answer before its own deadline as a JetStream error, which other
 * JetStream errors are: the server's answer. The transport tells the two apart by the client's message
 * ({@see NatsTransport::awaitOnConnection()}), so this runs the real client, whose wording a new version
 * could change.
 */
final class UnansweredPullTest extends TestCase
{
    /**
     * A pull the server never answered makes the next operation PING first; one the server ended with status
     * 408, as it ends every pull that found no messages, does not.
     */
    public function testOnlyAPullTheServerDidNotAnswerMakesTheNextOneCheckTheConnection(): void
    {
        $server = new PullAnsweringServer();
        $server->answerPulls = [false, true, true];
        // The client's own heartbeat is off, so every PING the server receives is the transport's.
        $client = new NatsClient(new NatsOptions(reconnectEnabled: false, pingIntervalSeconds: 0), $server);
        // The client gives up on an unanswered pull a second after max_batch_timeout.
        $transport = new ClientInjectedNatsTransport('nats://localhost:4222/stream/topic', [
            'max_batch_timeout' => 0.001,
            'ping_after_idle' => 30,
        ]);
        $transport->setClient($client);

        try {
            self::assertSame([], iterator_to_array($transport->get()));
            self::assertSame([], iterator_to_array($transport->get()));
            self::assertSame([], iterator_to_array($transport->get()));
        } finally {
            $transport->close();
        }

        // The handshake's PING, the unanswered pull, the check it called for, then two answered pulls.
        self::assertSame(['PING', 'PULL', 'PING', 'PULL', 'PULL'], $server->received);
    }
}
