<?php

/**
 * A handler failing during a failover - with `retry_handler: nats`, the NAK of a message whose handler failed while the
 * worker's server went away reaches NATS through another server, and the message comes back `nak_delay` later.
 *
 * Runs Symfony's Worker and retry listener, as `messenger:consume` does, with FrameworkBundle's default retry strategy,
 * against a stream with three replicas on the test cluster. The worker's DSN names the server that leads the stream,
 * with `reconnect: true`. The handler stops that server with `docker stop`, as a deploy or a rolling restart does,
 * and then fails. The server closes the connection on its way out, and the client, which reads the socket only while
 * an operation waits for a reply, has not read that close when the transport NAKs the delivery. A NAK written then
 * would still be taken by the socket and reach no server, and NATS would deliver the message again only after
 * `ack_wait`. The transport checks the connection before the NAK of a failed delivery (a PING): the client finds the
 * connection gone, recovers it on another server of the cluster, and sends the NAK there. The message comes back
 * `nak_delay` later and the handler runs again on the same worker.
 *
 * Mirrors the README "A Handler Failing During the Loss" section. Needs the test cluster and docker
 * (composer nats:start). Run: php examples/handler-failing-during-failover.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Composer\InstalledVersions;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

requireTestCluster('handler-failing-during-failover');
foreach (array_keys(EXAMPLE_CLUSTER) as $server) {
    requireDockerFor('handler-failing-during-failover', $server);
}

$stream = exampleStream('handler_failover');
// ack_wait 30 s: a NAK lost on the way would bring the message back only after it, past the worker's time limit.
$query = 'stream_replicas=3&retry_handler=nats&nak_delay=1&ack_wait=30';

// messenger:setup-transports, through any server of the cluster.
$setup = new NatsTransport(clusterDsn('nats_cluster_1', $stream, $query), [], new PhpSerializer());
$setup->setup();
$setup->close();

// The worker connects to the server that leads the stream, the one its handler stops.
$leader = streamPeers(clusterStreamInfo($stream))[0];
$logger = new ExampleLogger();
$worker = new NatsTransport(
    clusterDsn($leader, $stream, $query),
    ['reconnect' => true, 'max_batch_timeout' => 1],
    new PhpSerializer(),
    $logger,
);

/** The service locators the retry listener takes, holding the one transport and the one strategy. */
$locator = static fn (object $service): ContainerInterface => new class ($service) implements ContainerInterface {
    public function __construct(private readonly object $service)
    {
    }

    public function get(string $id): object
    {
        return $this->service;
    }

    public function has(string $id): bool
    {
        return true;
    }
};

$stopped = null;
try {
    $worker->send(new Envelope(new ExampleMessage('invoice 8')));

    // The handler stops the worker's server and fails on the first attempt, and succeeds on the next one.
    $attempts = [];
    $failedAt = 0.0;
    $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
        ExampleMessage::class => [static function (ExampleMessage $message) use (&$attempts, &$failedAt, &$stopped, $leader): void {
            $attempts[] = microtime(true);
            if (count($attempts) === 1) {
                $stopped = $leader;
                docker('stop', $leader);
                $failedAt = microtime(true);
                throw new RuntimeException('the payment provider did not answer');
            }
        }],
    ]))]);

    // What `messenger:consume` wires up, with a worker that stops once the message was handled, or after 15 s.
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($locator($worker), $locator(new MultiplierRetryStrategy(3, 1000, 2))));
    $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(15));
    $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$attempts): void {
        if (count($attempts) >= 2) {
            $event->getWorker()->stop();
        }
    });
    (new Worker(['nats' => $worker], $bus, $dispatcher))->run(['sleep' => 100000]);

    $waited = count($attempts) >= 2 ? $attempts[1] - $failedAt : 0.0;
    $info = clusterStreamInfo($stream, $leader === 'nats_cluster_1' ? 'nats_cluster_2' : 'nats_cluster_1');
    $newLeader = streamPeers($info)[0] ?? 'none';
    $consumer = withClusterJetStream($newLeader === 'none' ? 'nats_cluster_1' : $newLeader, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);

    if (count($attempts) !== 2 || $waited < 0.9 || $waited > 10 || ($consumer['num_ack_pending'] ?? null) !== 0 || !$logger->logged('Reconnected')) {
        throw new RuntimeException(sprintf(
            'expected the handler to run again about nak_delay (1 s) after it failed, not ack_wait (30 s), on the same worker, after a logged reconnect; got %d attempt(s), %.1f s after the failure, %s pending acknowledgement (symfony/messenger %s), log: %s',
            count($attempts),
            $waited,
            var_export($consumer['num_ack_pending'] ?? null, true),
            InstalledVersions::getPrettyVersion('symfony/messenger'),
            implode(' | ', $logger->lines),
        ));
    }

    printf(
        "OK handler-failing-during-failover: the handler stopped %s and failed; the NAK reached the cluster through another server and the message came back %.1f s later (nak_delay 1 s, ack_wait 30 s), where attempt 2 succeeded on the same worker; %s leads the stream now (symfony/messenger %s); the client logged %d lines:\n",
        $leader,
        $waited,
        $newLeader,
        InstalledVersions::getPrettyVersion('symfony/messenger'),
        count($logger->lines),
    );
    $logger->printLines();
} finally {
    $worker->close();
    if ($stopped !== null) {
        // Back as it was: the server rejoins the cluster and catches its replica up.
        docker('start', $stopped);
        waitForClusterServer($stopped);
    }
    withClusterJetStream('nats_cluster_1', static fn (JetStreamContext $jetStream) => $jetStream->deleteStream($stream)->await());
}
