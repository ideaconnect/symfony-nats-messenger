<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NatsMessenger\Tests\Support\ClientInjectedNatsTransport;
use IDCT\NatsMessenger\Tests\Support\FaultInjectingNatsServer;
use IDCT\NatsMessenger\Tests\Support\RecordingLogger;
use IDCT\NatsMessenger\Tests\Support\RttCountingNatsClient;
use IDCT\NatsMessenger\Tests\Support\SubscriptionLimitNatsServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function Amp\delay;

/**
 * The transport with `reconnect: true` (#81), running the real client over the scripted server behind a connection
 * that fails the way a real one does (`tests/Support/FaultInjectingNatsServer.php`).
 *
 * With the option on, the client owns the recovery of a lost connection, and the transport steps back: it leaves a
 * recovery in flight alone, keeps a connection the client has already recovered, and only replaces a connection the
 * client does not know to be lost. The four cases a failed PING can leave the client in are driven here one by one.
 * The transport's PINGs are counted through rtt(); the client's own, the one behind each inbox's SUB, does not go
 * through it, and its heartbeat is off.
 *
 * Budgets are short on purpose: request_timeout 0.1 s, connection_timeout 0.02 s, the client's reconnect delay 1 ms.
 */
final class ReconnectModeTest extends TestCase
{
    private const DSN = 'nats://localhost:4222/orders/orders.created';

    /**
     * How the client's recovery comes to be in flight when an operation starts: a PHP process runs the event loop
     * only inside a transport call, so the client notices a lost connection when an operation writes to it. That
     * operation starts the recovery and waits for it within its own budget; here the dial is held longer than
     * request_timeout, so the send fails with the client's timeout and leaves the client Connecting. The next send
     * finds it so: the transport sends no PING and dials nothing, and the send waits for the recovery and goes out
     * on the connection the client made. Without the option the transport would PING, give up at
     * connection_timeout and dial itself, which stops the client's recovery.
     */
    public function testAnOperationThatRunsIntoTheLostConnectionStartsTheRecoveryAndTheNextOneWaitsForIt(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $faults->holdNextDial(150);
            $faults->dropConnection();

            $failure = self::failureOf(static fn () => $transport->send(new Envelope(self::message('m-2'))));
            $stateAfterTheFailure = $client->state();

            $start = hrtime(true);
            $transport->send(new Envelope(self::message('m-3')));
            $elapsed = (hrtime(true) - $start) / 1e9;
        } finally {
            $transport->close();
        }

        self::assertStringContainsString('timed out', strtolower($failure->getMessage()), $failure::class . ': ' . $failure->getMessage());
        self::assertSame(ConnectionState::Connecting, $stateAfterTheFailure, 'the failed send left the recovery running');
        self::assertSame(0, $client->rtts, 'no PING during the recovery');
        self::assertSame(2, $server->sessions(), 'one connection from the client\'s recovery, none from the transport');
        self::assertSame(2, $faults->dialAttempts);
        self::assertSame(1, $client->statistics()->reconnects);
        self::assertGreaterThan(0.02, $elapsed, sprintf('the send waited for the held dial, it took %.3f s', $elapsed));
        self::assertSame(['PING', 'SUB _INBOX', 'PUBLISH orders.created'], $server->received(session: 1), 'the third send went out on the recovered connection; the second was never written to it');
    }

    /**
     * When the client recovers within the operation's own budget, the operation that ran into the lost connection
     * succeeds by itself: nothing fails, and the transport took no part in the recovery.
     */
    public function testAnOperationThatRunsIntoTheLostConnectionSucceedsWhenTheClientRecoversWithinItsBudget(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $faults->holdNextDial(30);
            $faults->dropConnection();

            $start = hrtime(true);
            $transport->send(new Envelope(self::message('m-2')));
            $elapsed = (hrtime(true) - $start) / 1e9;
            $transport->send(new Envelope(self::message('m-3')));
        } finally {
            $transport->close();
        }

        self::assertSame(0, $client->rtts);
        self::assertSame(2, $server->sessions());
        self::assertSame(1, $client->statistics()->reconnects);
        self::assertGreaterThan(0.02, $elapsed, sprintf('the send waited for the held dial, it took %.3f s', $elapsed));
        self::assertSame(['PING', 'SUB _INBOX', 'PUBLISH orders.created', 'PUBLISH orders.created'], $server->received(session: 1));
    }

    /**
     * The connection went silent (its peer is gone, nothing closed it): a send times out, the next one PINGs, the
     * PONG never comes, and the client still reports Open with no reconnect, since nothing told it the connection
     * is lost. The transport replaces it, as without the option: left to the client, every later send would time
     * out until its heartbeat had gone unanswered maxPingsOut + 1 times.
     */
    public function testASilentConnectionTheClientDoesNotKnowToBeLostIsReplaced(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $faults->goSilent();
            $failure = self::failureOf(static fn () => $transport->send(new Envelope(self::message('m-2'))));
            $transport->send(new Envelope(self::message('m-3')));
        } finally {
            $transport->close();
        }

        self::assertStringContainsString('timed out', strtolower($failure->getMessage()), $failure::class . ': ' . $failure->getMessage());
        self::assertSame(1, $client->rtts, 'the send after the failure checked the connection');
        self::assertSame(2, $server->sessions(), 'and replaced it, since the PING went unanswered on a connection the client reports Open');
        self::assertSame(0, $client->statistics()->reconnects, 'the client never recovered anything: the transport dialled');
        self::assertContains('PUBLISH orders.created', $server->received(session: 1));
    }

    /**
     * The transport's PING fails to write: the client reads the failed write as a lost connection and recovers at
     * once, and the PING reports its failure because its PONG belonged to the lost connection. The transport keeps
     * the connection the client made instead of replacing a connection that works: two connections in all, not
     * three.
     */
    public function testAConnectionTheClientRecoveredBehindAFailedPingIsKept(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults, pingAfterIdle: 0.05);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            delay(0.06);
            $faults->failNextWriteContaining('PING');
            $transport->send(new Envelope(self::message('m-2')));
        } finally {
            $transport->close();
        }

        self::assertSame(1, $client->rtts, 'the idle connection was checked');
        self::assertSame(1, $client->statistics()->reconnects, 'the client recovered the connection behind the failed PING');
        self::assertSame(2, $server->sessions(), 'the transport kept the connection the client made');
        self::assertContains('PUBLISH orders.created', $server->received(session: 1));
    }

    /**
     * As above, with the client's dial held: the PING's failure leaves the client Connecting. The transport leaves
     * the recovery to run, and the send waits for it within request_timeout. Without the option the transport
     * would have stopped the recovery and dialled itself.
     */
    public function testARecoveryStartedByAFailedPingIsLeftToRunAndTheOperationWaitsForIt(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults, pingAfterIdle: 0.05);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            delay(0.06);
            $faults->holdNextDial(60);
            $faults->failNextWriteContaining('PING');
            $start = hrtime(true);
            $transport->send(new Envelope(self::message('m-2')));
            $elapsed = (hrtime(true) - $start) / 1e9;
        } finally {
            $transport->close();
        }

        self::assertSame(1, $client->rtts);
        self::assertSame(1, $client->statistics()->reconnects);
        self::assertSame(2, $server->sessions(), 'the recovery was left alone and no dial of the transport\'s own ran');
        self::assertGreaterThan(0.04, $elapsed, sprintf('the send waited for the held dial, it took %.3f s', $elapsed));
        self::assertContains('PUBLISH orders.created', $server->received(session: 1));
    }

    /**
     * A worker's view of an outage the client recovers from: the pull that runs into the lost connection starts
     * the recovery, waits for it within its own budget and ends as an empty batch, nothing is thrown, and the pull
     * after it delivers what was sent meanwhile. Without the option the pull would fail and end the worker's run.
     */
    public function testAPullThatRunsIntoTheLostConnectionEndsEmptyAndTheNextOneDelivers(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            $first = iterator_to_array($transport->get(), false);
            $faults->holdNextDial(150);
            $faults->dropConnection();

            $start = hrtime(true);
            $duringRecovery = iterator_to_array($transport->get(), false);
            $elapsed = (hrtime(true) - $start) / 1e9;
            $transport->send(new Envelope(self::message('m-2')));
            $afterRecovery = iterator_to_array($transport->get(), false);
        } finally {
            $transport->close();
        }

        self::assertCount(1, $first);
        self::assertSame([], $duringRecovery, 'the pull that ran into the lost connection ended empty');
        self::assertGreaterThan(0.1, $elapsed, sprintf('after waiting for the held dial, it took %.3f s', $elapsed));
        self::assertLessThan(1.6, $elapsed, sprintf('within the pull\'s own budget, it took %.3f s', $elapsed));
        self::assertCount(1, $afterRecovery, 'the pull after the recovery delivered the message sent meanwhile');
        self::assertSame(0, $client->rtts, 'no PING: the client recovered, and a pull the server ended is no failure');
        self::assertSame(2, $server->sessions());
        self::assertSame(1, $client->statistics()->reconnects);
    }

    /**
     * A recovery that gives up: the dials are refused, the acknowledgement written while the client was recovering
     * was buffered, and the client discards the buffer when it gives up. Nothing waits for an acknowledgement, so the
     * only trace is the client's report, which goes to the logger the transport was given, at error level, and to the
     * error listener. The message comes back after ack_wait, which is the right outcome; the operator is told why.
     * The next operation finds the client Closed, dials again, which runs the client's attempt loop once more, and
     * fails with the client's exception: exhaustion propagates rather than being flattened into an empty result.
     */
    public function testAReconnectThatGivesUpWithABufferedAcknowledgementIsReportedToTheLoggerAndTheNextOperationFails(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        $logger = new RecordingLogger();
        [$transport, $client] = self::transportOver($faults, pingAfterIdle: 0.05, logger: $logger);

        try {
            $transport->send(new Envelope(self::message('m-1')));
            [$received] = iterator_to_array($transport->get(), false);
            delay(0.06);
            $faults->holdNextDial(100);
            $faults->refuseDials = true;
            $faults->failNextWriteContaining('PING');

            $transport->ack($received);
            $acknowledgedAtOnce = $client->state();
            delay(0.3);
            $stateAfterTheRecoveryGaveUp = $client->state();
            $dialsAfterTheRecoveryGaveUp = $faults->dialAttempts;

            $failure = self::failureOf(static fn () => $transport->send(new Envelope(self::message('m-2'))));
        } finally {
            $transport->close();
        }

        self::assertSame(ConnectionState::Connecting, $acknowledgedAtOnce, 'ack() returned while the client was recovering: the frame was buffered');
        self::assertSame(ConnectionState::Closed, $stateAfterTheRecoveryGaveUp);
        self::assertSame(4, $dialsAfterTheRecoveryGaveUp, 'the first dial and the three attempts of the recovery');
        self::assertSame([], $server->acks(), 'the buffered acknowledgement never reached the server');
        $discards = array_values(array_filter($logger->messagesAt('error'), static fn (string $message): bool => str_contains($message, 'buffered publishes were discarded')));
        self::assertCount(1, $discards, 'the discard was reported at error level: ' . implode(' | ', $logger->messagesAt('error')));
        self::assertStringContainsString('Reconnect exhausted', $discards[0]);
        self::assertSame(8, $faults->dialAttempts, 'the next operation dialled again: one dial and the three attempts of its recovery, as the client runs a failed first dial through its loop');
        self::assertStringContainsString('exhausted', strtolower($failure->getMessage()), $failure::class . ': ' . $failure->getMessage());
    }

    private static function server(): SubscriptionLimitNatsServer
    {
        return new SubscriptionLimitNatsServer('orders', 'orders.created', 'worker');
    }

    /**
     * @return array{ClientInjectedNatsTransport, RttCountingNatsClient}
     */
    private static function transportOver(FaultInjectingNatsServer $connection, float $pingAfterIdle = 30, ?RecordingLogger $logger = null): array
    {
        $client = new RttCountingNatsClient(new NatsOptions(
            connectTimeoutMs: 100,
            requestTimeoutMs: 100,
            reconnectEnabled: true,
            maxReconnectAttempts: 3,
            reconnectDelayMs: 1,
            reconnectMaxDelayMs: 2,
            reconnectJitterMs: 0,
            pingIntervalSeconds: 0,
            logger: $logger,
        ), $connection);
        $transport = new ClientInjectedNatsTransport(self::DSN, [
            'consumer' => 'worker',
            'max_batch_timeout' => 0.5,
            'ping_after_idle' => $pingAfterIdle,
            'reconnect' => true,
            'max_reconnect_attempts' => 3,
            'request_timeout' => 0.1,
            'connection_timeout' => 0.02,
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

    private static function failureOf(\Closure $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $failure) {
            return $failure;
        }

        self::fail('expected the operation to fail');
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
