<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit;

use Amp\Future;
use IDCT\NATS\Connection\ConnectionStats;
use IDCT\NATS\Connection\Enum\ConnectionState;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\JetStream\Enum\NoRespondersCause;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NATS\JetStream\Models\ConsumerInfo;
use IDCT\NatsMessenger\Tests\Support\ClientInjectedNatsTransport;
use IDCT\NatsMessenger\Tests\Support\FaultInjectingNatsServer;
use IDCT\NatsMessenger\Tests\Support\RttCountingNatsClient;
use IDCT\NatsMessenger\Tests\Support\SubscriptionLimitNatsServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * A pull that gets 503 from a server that is going away: a graceful stop or lame duck mode (#106).
 *
 * nats-server answers a pull with 503 (no responders) whenever nothing is subscribed to the consumer's pull subject: for
 * a consumer that does not exist, and also while a server that is going away has stopped the consumer and not yet closed
 * the connection. A graceful stop of NATS 2.15 takes about 10 ms for each durable consumer on a file stream; lame duck
 * mode stops JetStream at once and keeps the connections for its grace period (10 s by default). A worker whose pull
 * landed there read the 503 as a missing consumer and ended, with or without `reconnect: true`. After a 503 the transport
 * asks the client why (JetStreamContext::diagnoseNoResponders(), client 2.29): ServerGoingAway is an empty batch, any
 * other cause keeps the 503's reading.
 *
 * The first tests double the client and its JetStream context, to drive each cause and each state of the connection; the
 * others run the real client over the scripted server (`tests/Support/SubscriptionLimitNatsServer.php`) behind a
 * connection that can close right after the question went out (`tests/Support/FaultInjectingNatsServer.php`), as the
 * stopping server closes it, or with the server announcing lame duck mode. An operation that has to succeed gets
 * BUDGET_MS, which it never uses up; only the question a test wants to go unanswered on a connection that stays gets a
 * short budget, where a paused process just ends it sooner.
 */
final class GracefulStopPullTest extends TestCase
{
    private const DSN = 'nats://localhost:4222/orders/orders.created';

    private const BUDGET_MS = 5000;

    private const SHORT_BUDGET_MS = 50;

    private const PULL_503 = 'JetStream pull request ended with status 503';

    private const CONSUMER_NOT_FOUND = '{"type":"io.nats.jetstream.api.v1.consumer_info_response","error":{"code":404,"err_code":10014,"description":"consumer not found"}}';

    private const QUESTION = '$JS.API.CONSUMER.INFO.orders.worker';

    /**
     * A server going away is an empty batch: without `reconnect` the client has closed the connection with the server,
     * and the operation after the pull checks the connection first (here a dial, as the client is Closed). With
     * auto_setup nothing is provisioned on the server that is going away. Before #106 the 503 ended the worker, or,
     * with auto_setup, setup() ran against that server and failed.
     */
    #[DataProvider('autoSetupModes')]
    public function testA503FromAServerGoingAwayIsAnEmptyBatch(bool $autoSetup): void
    {
        $state = ConnectionState::Idle;
        $client = $this->client($state);
        $client->expects(self::never())->method('statistics');
        $jetStream = $this->createMock(JetStreamContext::class);
        $client->method('jetStream')->willReturn($jetStream);
        $jetStream->expects(self::once())->method('fetchBatch')->willReturn(Future::error(new JetStreamException(self::PULL_503, 503)));
        // Made when called, so that the data set without auto_setup leaves no failed Future nobody awaits.
        $jetStream->expects(self::exactly($autoSetup ? 1 : 0))->method('getConsumer')->willReturnCallback(static fn (): Future => Future::error(self::consumerNotFound()));
        $jetStream->expects(self::exactly($autoSetup ? 1 : 0))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly($autoSetup ? 1 : 0))->method('addConsumer')->willReturn(Future::complete(self::consumerInfo()));
        $this->diagnose($jetStream, [static function () use (&$state): NoRespondersCause {
            $state = ConnectionState::Closed;

            return NoRespondersCause::ServerGoingAway;
        }]);

        $transport = $this->transport($client, ['auto_setup' => $autoSetup]);

        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function autoSetupModes(): iterable
    {
        yield 'without auto_setup' => [false];
        yield 'with auto_setup' => [true];
    }

    /**
     * With `reconnect`, the operation after an empty batch from a server going away checks the connection first, as
     * after `409 Server Shutdown`, when the client has a connection to check; a recovery in flight is left to the
     * client.
     */
    #[DataProvider('connectionsAfterAServerGoingAway')]
    public function testTheOperationAfterAServerGoingAwayChecksTheConnectionTheClientHas(ConnectionState $stateAfter, int $reconnectsAfter, int $checks): void
    {
        $state = ConnectionState::Idle;
        $reconnects = 0;
        $client = $this->client($state);
        $client->method('statistics')->willReturnCallback(static function () use (&$reconnects): ConnectionStats {
            return new ConnectionStats(0, 0, 0, 0, $reconnects);
        });
        $client->expects(self::exactly($checks))->method('rtt')->willReturn(Future::complete(0.001));
        $jetStream = $this->createMock(JetStreamContext::class);
        $client->method('jetStream')->willReturn($jetStream);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error(new JetStreamException(self::PULL_503, 503)),
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );
        $this->diagnose($jetStream, [static function () use (&$state, &$reconnects, $stateAfter, $reconnectsAfter): NoRespondersCause {
            $state = $stateAfter;
            $reconnects = $reconnectsAfter;

            return NoRespondersCause::ServerGoingAway;
        }]);

        $transport = $this->transport($client, ['reconnect' => true]);

        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * @return iterable<string, array{ConnectionState, int, int}>
     */
    public static function connectionsAfterAServerGoingAway(): iterable
    {
        yield 'the client is recovering the connection: the next pull leaves the recovery to it' => [ConnectionState::Connecting, 0, 0];
        yield 'the client has replaced the connection: the next pull checks the new one' => [ConnectionState::Open, 1, 1];
        yield 'a lame duck server keeps the connection: the next pull checks it' => [ConnectionState::Open, 0, 1];
    }

    /**
     * Any answer comes from a server that stays, and the 503 keeps its reading as a missing consumer: without
     * auto_setup it propagates, unchanged. That holds for an answer that the consumer exists as well, which a server
     * that is going away never gives. An answer also shows that the connection works, so the next operation does not
     * check it first.
     */
    #[DataProvider('answers')]
    public function testA503KeepsItsReadingWhenTheServerAnswers(NoRespondersCause $answer): void
    {
        $state = ConnectionState::Idle;
        $client = $this->client($state);
        $client->expects(self::never())->method('rtt');
        $jetStream = $this->createMock(JetStreamContext::class);
        $client->method('jetStream')->willReturn($jetStream);
        $pull = new JetStreamException(self::PULL_503, 503);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error($pull),
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );
        $this->diagnose($jetStream, [$answer]);

        $transport = $this->transport($client);

        self::assertSame($pull, self::failureOf(static fn () => iterator_to_array($transport->get())));
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * @return iterable<string, array{NoRespondersCause}>
     */
    public static function answers(): iterable
    {
        yield 'the consumer' => [NoRespondersCause::ConsumerFound];
        yield '404 consumer not found' => [NoRespondersCause::ConsumerNotFound];
        yield '404 stream not found' => [NoRespondersCause::StreamNotFound];
        yield 'another JetStream error' => [NoRespondersCause::JetStreamError];
    }

    /**
     * No answer on a connection that stays, as for a user the server lets pull but not ask for consumer info, keeps
     * the 503's reading too: an empty batch would hide a missing consumer behind a worker that never stops polling. The
     * operation after it checks the connection first, as after any operation that failed on it.
     */
    #[DataProvider('reconnectModes')]
    public function testA503KeepsItsReadingWhenNothingAnswersOnAConnectionThatStays(bool $reconnect): void
    {
        $state = ConnectionState::Idle;
        $client = $this->client($state);
        $client->method('statistics')->willReturn(new ConnectionStats(0, 0, 0, 0, 0));
        $client->expects(self::once())->method('rtt')->willReturn(Future::complete(0.001));
        $jetStream = $this->createMock(JetStreamContext::class);
        $client->method('jetStream')->willReturn($jetStream);
        $pull = new JetStreamException(self::PULL_503, 503);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnOnConsecutiveCalls(
            Future::error($pull),
            Future::error(new JetStreamException('JetStream pull request ended with status 408: Request Timeout', 408)),
        );
        $this->diagnose($jetStream, [NoRespondersCause::NoAnswer]);

        $transport = $this->transport($client, ['reconnect' => $reconnect]);

        self::assertSame($pull, self::failureOf(static fn () => iterator_to_array($transport->get())));
        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function reconnectModes(): iterable
    {
        yield 'without reconnect' => [false];
        yield 'with reconnect' => [true];
    }

    /**
     * Only a 503 calls for the diagnosis: a pull that found no messages, one whose consumer was deleted while it
     * waited and any other status keep their readings, and nothing asks for the consumer.
     */
    #[DataProvider('otherStatuses')]
    public function testOnlyA503AsksWhyThePullGotNoResponders(JetStreamException $pull, bool $empty): void
    {
        $state = ConnectionState::Idle;
        $client = $this->client($state);
        $jetStream = $this->createMock(JetStreamContext::class);
        $client->method('jetStream')->willReturn($jetStream);
        $jetStream->expects(self::once())->method('fetchBatch')->willReturn(Future::error($pull));
        $jetStream->expects(self::never())->method('diagnoseNoResponders');

        $transport = $this->transport($client);

        if ($empty) {
            self::assertSame([], iterator_to_array($transport->get()));
        } else {
            self::assertSame($pull, self::failureOf(static fn () => iterator_to_array($transport->get())));
        }
    }

    /**
     * @return iterable<string, array{JetStreamException, bool}>
     */
    public static function otherStatuses(): iterable
    {
        yield '404 no messages' => [new JetStreamException('JetStream pull request ended with status 404: No Messages', 404), true];
        yield '409 consumer deleted' => [new JetStreamException('JetStream pull request ended with status 409: Consumer Deleted', 409), false];
        yield '500' => [new JetStreamException('backend unavailable', 500), false];
    }

    /**
     * With auto_setup, the pull after provisioning again gets the same reading: its 503 from a server going away is an
     * empty batch, as on the first pull.
     */
    public function testA503OfThePullAfterProvisioningAgainFromAServerGoingAwayIsAnEmptyBatch(): void
    {
        $state = ConnectionState::Idle;
        $client = $this->client($state);
        $jetStream = $this->createMock(JetStreamContext::class);
        $client->method('jetStream')->willReturn($jetStream);
        $jetStream->expects(self::exactly(2))->method('fetchBatch')->willReturnCallback(static fn (): Future => Future::error(new JetStreamException(self::PULL_503, 503)));
        // setup() on first use, and again after the first pull's 503: the consumer is missing each time.
        $jetStream->expects(self::exactly(2))->method('getConsumer')->willReturnCallback(static fn (): Future => Future::error(self::consumerNotFound()));
        $jetStream->expects(self::exactly(2))->method('addStream')->willReturn(Future::complete());
        $jetStream->expects(self::exactly(2))->method('addConsumer')->willReturn(Future::complete(self::consumerInfo()));
        $this->diagnose($jetStream, [
            NoRespondersCause::ConsumerNotFound,
            static function () use (&$state): NoRespondersCause {
                $state = ConnectionState::Closed;

                return NoRespondersCause::ServerGoingAway;
            },
        ]);

        $transport = $this->transport($client, ['auto_setup' => true]);

        self::assertSame([], iterator_to_array($transport->get()));
    }

    /**
     * The real client without `reconnect`, over a connection the server closes right after the question went out, as a
     * server that stops gracefully closes it: the pull is an empty batch, and the next one dials a new connection and
     * pulls there. Fails before #106 with the 503.
     */
    public function testWithTheRealClientAPullOnAStoppingServerIsAnEmptyBatchAndTheNextOneDialsAgain(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport] = self::transportOver($faults, reconnect: false);

        try {
            self::assertSame([], iterator_to_array($transport->get()));

            $server->pullsFindNoResponder = true;
            $faults->dropConnectionAfterNextWriteContaining(self::QUESTION);
            $duringTheStop = iterator_to_array($transport->get());

            $server->pullsFindNoResponder = false;
            $afterIt = iterator_to_array($transport->get());
        } finally {
            $transport->close();
        }

        self::assertSame([], $duringTheStop);
        self::assertSame([], $afterIt);
        self::assertStringContainsString(self::QUESTION, implode('', $faults->lostWrites()), 'the client asked for the consumer');
        self::assertSame(2, $server->sessions(), 'the pull after the stop went out on a new connection');
        self::assertSame(['PULL _INBOX.JS.FETCH'], array_values(array_filter($server->received(session: 1), static fn (string $line): bool => str_starts_with($line, 'PULL'))));
    }

    /**
     * The real client with `reconnect`: it recovers the connection on its own, and would keep the question waiting
     * across the reconnect, up to request_timeout, which is BUDGET_MS here. Its diagnosis ends once it is recovering or
     * has recovered, long before that: the pull is an empty batch, and the next one goes out on the recovered
     * connection.
     */
    public function testWithTheRealClientAndReconnectAPullOnAStoppingServerIsAnEmptyBatchWithoutWaitingForTheRequestTimeout(): void
    {
        $server = self::server();
        $faults = new FaultInjectingNatsServer($server);
        [$transport, $client] = self::transportOver($faults, reconnect: true);

        try {
            self::assertSame([], iterator_to_array($transport->get()));

            $server->pullsFindNoResponder = true;
            $faults->dropConnectionAfterNextWriteContaining(self::QUESTION);
            $start = hrtime(true);
            $duringTheStop = iterator_to_array($transport->get());
            $elapsed = (hrtime(true) - $start) / 1e9;

            $server->pullsFindNoResponder = false;
            $afterIt = iterator_to_array($transport->get());
        } finally {
            $transport->close();
        }

        self::assertSame([], $duringTheStop);
        self::assertSame([], $afterIt);
        self::assertLessThan(self::BUDGET_MS / 2000, $elapsed, sprintf('the pull waited %.3f s for the unanswered question', $elapsed));
        self::assertSame(1, $client->statistics()->reconnects);
        self::assertSame(2, $server->sessions());
        self::assertSame(['PULL _INBOX.JS.FETCH'], array_values(array_filter($server->received(session: 1), static fn (string $line): bool => str_starts_with($line, 'PULL'))));
    }

    /**
     * The real client, with and without `reconnect`, against a server in lame duck mode: it has stopped JetStream,
     * leaves the question unanswered, says it is going away (an INFO with `ldm`) and keeps the connection open for its
     * grace period. The pull is an empty batch long before request_timeout (BUDGET_MS here), and so is the next pull,
     * which asks nothing, as the client already knows; the connection stays and is checked before the next pull. With
     * client 2.28 the question waited for request_timeout on a connection that stays and the 503 then propagated, and
     * before #106 the 503 propagated at once.
     */
    #[DataProvider('reconnectModes')]
    public function testWithTheRealClientAPullOnAServerInLameDuckModeIsAnEmptyBatchWhileTheConnectionStays(bool $reconnect): void
    {
        $server = self::server();
        [$transport, $client] = self::transportOver(new FaultInjectingNatsServer($server), reconnect: $reconnect);

        try {
            self::assertSame([], iterator_to_array($transport->get()));

            $server->pullsFindNoResponder = true;
            $server->lameDuckOnConsumerInfo = true;
            $start = hrtime(true);
            $first = iterator_to_array($transport->get());
            $elapsed = (hrtime(true) - $start) / 1e9;
            $second = iterator_to_array($transport->get());
        } finally {
            $transport->close();
        }

        self::assertSame([], $first);
        self::assertSame([], $second);
        self::assertLessThan(self::BUDGET_MS / 2000, $elapsed, sprintf('the pull waited %.3f s for the unanswered question', $elapsed));
        self::assertTrue($client->serverInfo()?->lameDuckMode);
        self::assertSame(1, $server->sessions(), 'the connection stayed');
        self::assertSame(1, $client->rtts, 'the pull after the first checked the connection first');
        $questions = array_filter($server->received(), static fn (string $line): bool => $line === 'PUBLISH ' . self::QUESTION);
        self::assertCount(1, $questions, 'the second pull asked nothing');
    }

    /**
     * The real client against a server that stays, with the consumer missing: the question gets 404 and the 503
     * propagates, as before #106.
     */
    public function testWithTheRealClientA503FromAServerThatAnswersTheQuestionPropagates(): void
    {
        $server = self::server();
        $server->pullsFindNoResponder = true;
        $server->consumerInfoReply = self::CONSUMER_NOT_FOUND;
        [$transport] = self::transportOver(new FaultInjectingNatsServer($server), reconnect: false);

        try {
            $failure = self::failureOf(static fn () => iterator_to_array($transport->get()));
        } finally {
            $transport->close();
        }

        self::assertInstanceOf(JetStreamException::class, $failure);
        self::assertSame(503, $failure->getCode());
        self::assertSame(self::PULL_503, $failure->getMessage());
        self::assertSame(['PULL _INBOX.JS.FETCH', 'PUBLISH ' . self::QUESTION], array_values(array_filter(
            $server->received(),
            static fn (string $line): bool => str_starts_with($line, 'PULL') || str_starts_with($line, 'PUBLISH'),
        )));
        self::assertSame(1, $server->sessions());
    }

    /**
     * The real client against a server that leaves the question unanswered and keeps the connection: the 503
     * propagates once the client's request times out, and the next operation checks the connection first.
     */
    public function testWithTheRealClientA503WhoseQuestionGoesUnansweredOnAConnectionThatStaysPropagates(): void
    {
        $server = self::server();
        $server->pullsFindNoResponder = true;
        [$transport, $client] = self::transportOver(new FaultInjectingNatsServer($server), reconnect: false);

        try {
            $client->requestBudgetMs = self::SHORT_BUDGET_MS;
            try {
                $failure = self::failureOf(static fn () => iterator_to_array($transport->get()));
            } finally {
                $client->requestBudgetMs = null;
            }

            $server->pullsFindNoResponder = false;
            $transport->send(new Envelope(new \stdClass()));
        } finally {
            $transport->close();
        }

        self::assertSame(self::PULL_503, $failure->getMessage());
        self::assertSame(1, $client->rtts, 'the send after it checked the connection first');
        self::assertSame(1, $server->sessions());
    }

    /**
     * How often one test may read the client's state: the transport looks at it a few times in each of these tests, and
     * a loop that never ends then fails within moments instead of hanging.
     */
    private const MAX_STATE_READS = 100;

    /**
     * A client double whose state() reads $state, and whose connect() opens it, once: none of these tests dials again.
     *
     * @return NatsClient&MockObject
     */
    private function client(ConnectionState &$state): NatsClient
    {
        $reads = 0;
        $client = $this->createMock(NatsClient::class);
        $client->method('state')->willReturnCallback(static function () use (&$state, &$reads): ConnectionState {
            if (++$reads > self::MAX_STATE_READS) {
                throw new \LogicException(sprintf('state() read more than %d times: a loop that never ends.', self::MAX_STATE_READS));
            }

            return $state;
        });
        $client->expects(self::once())->method('connect')->willReturnCallback(static function () use (&$state): Future {
            $state = ConnectionState::Open;

            return Future::complete();
        });

        return $client;
    }

    /**
     * Each diagnoseNoResponders() call, for the transport's stream and consumer, takes the next cause: a cause, or a
     * closure that returns one.
     *
     * @param list<NoRespondersCause|\Closure(): NoRespondersCause> $causes
     */
    private function diagnose(JetStreamContext&MockObject $jetStream, array $causes): void
    {
        $jetStream->expects(self::exactly(count($causes)))->method('diagnoseNoResponders')->with('orders', 'worker')->willReturnCallback(
            static function () use (&$causes): Future {
                $cause = array_shift($causes);
                self::assertNotNull($cause);

                return Future::complete($cause instanceof \Closure ? $cause() : $cause);
            },
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function transport(NatsClient $client, array $options = []): ClientInjectedNatsTransport
    {
        $transport = new ClientInjectedNatsTransport(self::DSN, ['consumer' => 'worker'] + $options, new PhpSerializer());
        $transport->setClient($client);

        return $transport;
    }

    private static function server(): SubscriptionLimitNatsServer
    {
        return new SubscriptionLimitNatsServer('orders', 'orders.created', 'worker');
    }

    /**
     * @return array{ClientInjectedNatsTransport, RttCountingNatsClient}
     */
    private static function transportOver(FaultInjectingNatsServer $connection, bool $reconnect): array
    {
        $client = new RttCountingNatsClient(new NatsOptions(
            connectTimeoutMs: self::BUDGET_MS,
            requestTimeoutMs: self::BUDGET_MS,
            reconnectEnabled: $reconnect,
            maxReconnectAttempts: 3,
            reconnectDelayMs: 1,
            reconnectMaxDelayMs: 2,
            reconnectJitterMs: 0,
            pingIntervalSeconds: 0,
        ), $connection);
        $transport = new ClientInjectedNatsTransport(self::DSN, [
            'consumer' => 'worker',
            'max_batch_timeout' => 0.5,
            'ping_after_idle' => 30,
            'reconnect' => $reconnect,
            'max_reconnect_attempts' => 3,
            'request_timeout' => self::BUDGET_MS / 1000,
            'connection_timeout' => self::BUDGET_MS / 1000,
        ], new PhpSerializer());
        $transport->setClient($client);

        return [$transport, $client];
    }

    private static function consumerNotFound(): JetStreamException
    {
        return new JetStreamException('consumer not found', 404, null, 10014);
    }

    private static function consumerInfo(): ConsumerInfo
    {
        return new ConsumerInfo(
            streamName: 'orders',
            name: 'worker',
            push: false,
            raw: ['config' => ['ack_policy' => 'explicit', 'deliver_policy' => 'all', 'filter_subject' => 'orders.created']],
        );
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
}
