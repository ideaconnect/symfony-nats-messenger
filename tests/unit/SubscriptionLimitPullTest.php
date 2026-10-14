<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Exception\SubscriptionLimitException;
use IDCT\NatsMessenger\Tests\Support\ClientInjectedNatsTransport;
use IDCT\NatsMessenger\Tests\Support\RttCountingNatsClient;
use IDCT\NatsMessenger\Tests\Support\SubscriptionLimitNatsServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function Amp\delay;

/**
 * A pull at the connection's subscription limit (#76), with the real client over a scripted server
 * (`tests/Support/SubscriptionLimitNatsServer.php`) that refuses a SUB beyond its limit, keeping the connection open.
 *
 * The transport holds up to two subscriptions on a connection: the reply inbox of its requests, which the first send()
 * subscribes here, and the inbox of the pull in progress. At a limit of one the server refuses the pull's inbox with
 * "maximum subscriptions exceeded". The client used to report it as a plain ConnectionException, which left get() as
 * it was, though Symfony's ReceiverInterface::get() declares a TransportException. Since client 2.27.0 it is a
 * SubscriptionLimitException, which get() turns into a TransportException naming the stream, the consumer and the
 * limit. The next operation still checks the connection first: the server sends the same error when an account's
 * limit is lowered, and then closes the connection behind it.
 *
 * The transport's checks are counted through rtt(), since the client's heartbeat and the PING it writes behind each
 * inbox's SUB are PINGs too. ping_after_idle stays at 30: at 0 no check would run at all.
 */
final class SubscriptionLimitPullTest extends TestCase
{
    private const DSN = 'nats://localhost:4222/orders/orders.created';
    private const LIMIT_MESSAGE = 'Cannot pull from NATS stream "orders", consumer "worker": the subscription limit was exceeded. '
        . 'This transport requires capacity for at least two subscriptions per connection. Check server, account and user limits.';
    private const SERVER_ERR = "Server sent error frame: 'maximum subscriptions exceeded'";

    /**
     * The common shape: at a limit of one, after a send() whose request inbox took the slot, the pull's own read meets
     * the -ERR. get() fails at once, well within max_batch_timeout, with the TransportException naming the limit and
     * the client's SubscriptionLimitException as its previous one. The next operation checks the connection first;
     * the server answers, so the connection is kept, and the send goes out on it.
     */
    public function testAtALimitOfOneThePullFailsWithATransportExceptionNamingTheLimit(): void
    {
        $server = self::server();
        $server->limit = 1;
        [$transport, $client] = self::transportOver($server);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $start = hrtime(true);
            $failure = self::failureOf($transport);
            $elapsed = (hrtime(true) - $start) / 1e9;
            $checksAfterThePull = $client->rtts;
            $transport->send(new Envelope(self::message('m-2')));
        } finally {
            $transport->close();
        }

        self::assertInstanceOf(TransportException::class, $failure, $failure::class . ': ' . $failure->getMessage());
        self::assertSame(self::LIMIT_MESSAGE, $failure->getMessage());
        self::assertInstanceOf(SubscriptionLimitException::class, $failure->getPrevious());
        self::assertSame(self::SERVER_ERR, $failure->getPrevious()->getMessage());
        self::assertLessThan(0.5, $elapsed, sprintf('get() failed after %.3f s, with max_batch_timeout 0.5 s', $elapsed));
        self::assertSame(0, $checksAfterThePull);
        self::assertSame(1, $client->rtts, 'the send after the refused pull checked the connection');
        self::assertSame(1, $server->sessions(), 'and kept it, since the server answered');
        self::assertSame(
            ['PING', 'SUB _INBOX', 'PUBLISH orders.created', 'SUB _INBOX.JS.FETCH', 'REFUSED _INBOX.JS.FETCH', 'PULL _INBOX.JS.FETCH', 'UNSUB 2', 'PING', 'PUBLISH orders.created'],
            $server->received(),
        );
    }

    /**
     * The client's heartbeat reads the -ERR while the pull's SUB write is still under way: the pull learns of the
     * refusal from the client's record of it and fails without sending its pull request. get() fails with the same
     * TransportException, the client's SubscriptionLimitException naming the inbox as its previous one, and the error
     * listener got the server's -ERR, typed the same. The next operation checks the connection and keeps it.
     */
    public function testAtALimitOfOneAPullWhoseRefusalTheHeartbeatReadsFailsTheSameWay(): void
    {
        $server = self::server();
        $server->limit = 1;
        $server->rejectedSubWriteEndsAfterAnotherRead = true;
        /** @var list<\Throwable> $reported */
        $reported = [];
        [$transport, $client] = self::transportOver($server, heartbeatSeconds: 0.05, errorListener: static function (\Throwable $error) use (&$reported): void {
            $reported[] = $error;
        });

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $start = hrtime(true);
            $failure = self::failureOf($transport);
            $elapsed = (hrtime(true) - $start) / 1e9;
            $checksAfterThePull = $client->rtts;
            $transport->send(new Envelope(self::message('m-2')));
        } finally {
            $transport->close();
        }

        self::assertInstanceOf(TransportException::class, $failure, $failure::class . ': ' . $failure->getMessage());
        self::assertSame(self::LIMIT_MESSAGE, $failure->getMessage());
        self::assertInstanceOf(SubscriptionLimitException::class, $failure->getPrevious());
        self::assertStringStartsWith(
            'JetStream pull fetch failed: the server may have rejected its reply-inbox subscription "_INBOX.JS.FETCH.',
            $failure->getPrevious()->getMessage(),
        );
        $limitReports = array_values(array_filter($reported, static fn (\Throwable $error): bool => $error->getMessage() === self::SERVER_ERR));
        self::assertNotSame([], $limitReports, "the heartbeat's read met the -ERR");
        self::assertInstanceOf(SubscriptionLimitException::class, $limitReports[0]);
        self::assertSame([], $server->pullInboxes(), 'no pull request went out for the refused inbox');
        self::assertLessThan(0.5, $elapsed, sprintf('get() failed after %.3f s, with max_batch_timeout 0.5 s', $elapsed));
        self::assertSame(0, $checksAfterThePull);
        self::assertSame(1, $client->rtts, 'the send after the refused pull checked the connection');
        self::assertSame(1, $server->sessions(), 'and kept it, since the server answered');
    }

    /**
     * At a limit of two, the request inbox and a pull's inbox fit. A pull delivers the message a send stored, with the
     * ack subject as its transport id, and the ack reaches the server. A second send and pull work the same way: each
     * pull released its inbox, so the connection never needed a third subscription. Nothing is refused, nothing is
     * checked, and the connection is never replaced.
     */
    public function testAtALimitOfTwoPullsDeliverAndAcknowledge(): void
    {
        $server = self::server();
        $server->limit = 2;
        [$transport, $client] = self::transportOver($server);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $first = array_values(iterator_to_array($transport->get(), false));
            self::assertCount(1, $first);
            $transport->ack($first[0]);

            $transport->send(new Envelope(self::message('m-2')));
            $second = array_values(iterator_to_array($transport->get(), false));
            self::assertCount(1, $second);
            $transport->ack($second[0]);
        } finally {
            $transport->close();
        }

        self::assertEquals(self::message('m-1'), $first[0]->getMessage());
        self::assertEquals(self::message('m-2'), $second[0]->getMessage());
        $acks = $server->acks();
        self::assertSame(['+ACK', '+ACK'], array_column($acks, 'payload'));
        self::assertSame(
            [$acks[0]['subject'], $acks[1]['subject']],
            [$first[0]->last(TransportMessageIdStamp::class)?->getId(), $second[0]->last(TransportMessageIdStamp::class)?->getId()],
            'each ack went to the subject its message came with',
        );
        self::assertStringStartsWith('$JS.ACK.orders.worker.', $acks[0]['subject']);
        self::assertSame(2, $server->peakSubscriptions());
        self::assertNotContains('REFUSED _INBOX.JS.FETCH', $server->received());
        self::assertSame(2, count(array_unique($server->pullInboxes())), 'each pull subscribed an inbox of its own');
        self::assertSame(0, $client->rtts);
        self::assertSame(1, $server->sessions());
    }

    /** @return iterable<string, array{string}> */
    public static function closingLimitErrors(): iterable
    {
        // The -ERR answers the write of the pull's SUB and the PING behind it, ahead of that PING's PONG.
        yield 'before the pull inbox is confirmed' => ['SUB _INBOX.JS.FETCH.'];
        // The PONG behind the SUB confirmed the inbox; the -ERR answers the pull request.
        yield 'after the pull inbox is confirmed' => ['$JS.API.CONSUMER.MSG.NEXT.'];
    }

    /**
     * The closing form: an account's limit is lowered below what the connection holds, and the server sends the same
     * -ERR and closes the connection 20 ms later. get() fails with the TransportException all the same. Nothing reads
     * the connection afterwards (the heartbeat is off), so the client still reports it Open after the server closed
     * it. The next operation checks it first, finds it gone, and goes out on a new connection.
     */
    #[DataProvider('closingLimitErrors')]
    public function testALimitErrorTheServerClosesTheConnectionBehindKeepsTheCheckBeforeTheNextOperation(string $needle): void
    {
        $server = self::server();
        [$transport, $client] = self::transportOver($server);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $server->limitErrorAndCloseOnNextWriteContaining($needle);
            $failure = self::failureOf($transport);
            $server->serverClose()->await();
            $stateAfterTheClose = $client->state();
            $transport->send(new Envelope(self::message('m-2')));
        } finally {
            $transport->close();
        }

        self::assertInstanceOf(TransportException::class, $failure, $failure::class . ': ' . $failure->getMessage());
        self::assertSame(self::LIMIT_MESSAGE, $failure->getMessage());
        self::assertInstanceOf(SubscriptionLimitException::class, $failure->getPrevious());
        self::assertSame(ConnectionState::Open, $stateAfterTheClose, 'no read met the EOF, so the client still reported the connection Open');
        self::assertSame(1, $client->rtts, 'the send checked the connection first');
        self::assertSame(2, $server->sessions(), 'and found it gone, so it opened a new one');
        self::assertContains('PUBLISH orders.created', $server->received(session: 1), 'the send went out on the new connection');
    }

    /**
     * The same closing form, where the client's heartbeat reads the EOF behind the -ERR, so the client knows the
     * connection is Closed before the next operation. That operation dials a new connection without a check.
     */
    public function testALimitErrorTheServerClosesTheConnectionBehindLeavesAClosedClientThatIsReplacedWithoutACheck(): void
    {
        $server = self::server();
        [$transport, $client] = self::transportOver($server, heartbeatSeconds: 0.05);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $server->limitErrorAndCloseOnNextWriteContaining('SUB _INBOX.JS.FETCH.');
            $failure = self::failureOf($transport);
            $server->serverClose()->await();
            self::waitUntil(static fn (): bool => $client->state() === ConnectionState::Closed);
            $transport->send(new Envelope(self::message('m-2')));
        } finally {
            $transport->close();
        }

        self::assertInstanceOf(TransportException::class, $failure, $failure::class . ': ' . $failure->getMessage());
        self::assertSame(self::LIMIT_MESSAGE, $failure->getMessage());
        self::assertInstanceOf(SubscriptionLimitException::class, $failure->getPrevious());
        self::assertSame(0, $client->rtts, 'a Closed client needs no check');
        self::assertSame(2, $server->sessions());
        self::assertContains('PUBLISH orders.created', $server->received(session: 1), 'the send went out on the new connection');
    }

    private static function server(): SubscriptionLimitNatsServer
    {
        return new SubscriptionLimitNatsServer('orders', 'orders.created', 'worker');
    }

    /**
     * @return array{ClientInjectedNatsTransport, RttCountingNatsClient}
     */
    private static function transportOver(SubscriptionLimitNatsServer $server, float $heartbeatSeconds = 0, ?\Closure $errorListener = null): array
    {
        $client = new RttCountingNatsClient(new NatsOptions(
            reconnectEnabled: false,
            pingIntervalSeconds: $heartbeatSeconds,
            errorListener: $errorListener,
        ), $server);
        $transport = new ClientInjectedNatsTransport(self::DSN, [
            'consumer' => 'worker',
            'max_batch_timeout' => 0.5,
            'ping_after_idle' => 30,
        ], new PhpSerializer());
        $transport->setClient($client);

        return [$transport, $client];
    }

    private static function message(string $id): \stdClass
    {
        $message = new \stdClass();
        $message->id = $id;

        return $message;
    }

    /** What get() failed with; get() is a generator, so it runs only once iterated. */
    private static function failureOf(ClientInjectedNatsTransport $transport): \Throwable
    {
        try {
            iterator_to_array($transport->get());
        } catch (\Throwable $failure) {
            return $failure;
        }

        self::fail('expected get() to fail');
    }

    private static function waitUntil(\Closure $condition): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (!$condition()) {
            if (hrtime(true) > $deadline) {
                self::fail('condition not reached within 2 s');
            }

            delay(0.001);
        }
    }
}
