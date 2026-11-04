<?php

/**
 * A handler failing during a failover - in both retry modes, the worker carries on through another server when its
 * handler fails while the worker's server goes away, and the message comes back.
 *
 * Runs Symfony's Worker and retry listener, as `messenger:consume` does, with FrameworkBundle's default retry strategy,
 * against a stream with three replicas on the test cluster, once per `retry_handler`. The worker's DSN names the
 * server that leads the stream, with `reconnect: true`. The handler stops that server with `docker stop`, as a deploy
 * or a rolling restart does, and then fails. The server closes the connection on its way out, and the client, which
 * reads the socket only while an operation waits for a reply, has not read that close when the handler returns:
 *
 * - `retry_handler: symfony` (the default): Symfony's retry listener sends the retry copy, a JetStream publish. Since
 *   client 2.28.0 the client sees the close before it writes, recovers the connection on another server of the
 *   cluster and publishes the copy there; the worker receives the copy and handles it. With client 2.27 the copy went
 *   into the closed socket, the publish timed out after `request_timeout` and the worker exited.
 * - `retry_handler: nats`: the transport checks the connection before the NAK of a failed delivery (a PING), the
 *   client recovers it on another server, and the NAK goes out there. The message comes back `nak_delay` later.
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

$results = [];
foreach (['symfony', 'nats'] as $retryHandler) {
    $stream = exampleStream('handler_failover_' . $retryHandler);
    // ack_wait 30 s: a NAK or retry copy lost on the way would bring the message back only after it, past the
    // worker's time limit.
    $query = 'stream_replicas=3&retry_handler=' . $retryHandler . '&nak_delay=1&ack_wait=30';

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

        // symfony mode: the retry copy is stored at once (scheduled messages are off, so its delay stamp has no
        // effect), well before request_timeout (10 s). nats mode: the message comes back after nak_delay (1 s).
        $tooEarly = $retryHandler === 'nats' ? 0.9 : 0.0;
        if (count($attempts) !== 2 || $waited < $tooEarly || $waited > 10 || ($consumer['num_ack_pending'] ?? null) !== 0 || ($consumer['num_pending'] ?? null) !== 0 || !$logger->logged('Reconnected')) {
            throw new RuntimeException(sprintf(
                'retry_handler=%s: expected the handler to run again on the same worker within 10 s of the failure (nats mode: about nak_delay, 1 s, after it), not after ack_wait (30 s), with nothing left pending, after a logged reconnect; got %d attempt(s), %.1f s after the failure, %s pending acknowledgement, %s pending delivery (symfony/messenger %s, client %s), log: %s',
                $retryHandler,
                count($attempts),
                $waited,
                var_export($consumer['num_ack_pending'] ?? null, true),
                var_export($consumer['num_pending'] ?? null, true),
                InstalledVersions::getPrettyVersion('symfony/messenger'),
                InstalledVersions::getPrettyVersion('idct/php-nats-jetstream-client'),
                implode(' | ', $logger->lines),
            ));
        }

        $results[] = sprintf(
            '  retry_handler=%s: the handler stopped %s and failed; %s, and attempt 2 ran %.2f s after the failure and succeeded on the same worker; %s leads the stream now; the client logged:',
            $retryHandler,
            $leader,
            $retryHandler === 'nats'
                ? 'the NAK reached the cluster through another server (nak_delay 1 s, ack_wait 30 s)'
                : 'Symfony\'s retry copy was stored through another server (request_timeout 10 s)',
            $waited,
            $newLeader,
        );
        foreach ($logger->lines as $line) {
            $results[] = '    ' . $line;
        }
    } finally {
        $worker->close();
        if ($stopped !== null) {
            // Back as it was: the server rejoins the cluster and catches its replica up.
            docker('start', $stopped);
            waitForClusterServer($stopped);
        }
        withClusterJetStream('nats_cluster_1', static fn (JetStreamContext $jetStream) => $jetStream->deleteStream($stream)->await());
    }
}

printf(
    "OK handler-failing-during-failover: in both retry modes the worker carried on through another server when its handler failed while its server stopped (symfony/messenger %s, client %s):\n%s\n",
    InstalledVersions::getPrettyVersion('symfony/messenger'),
    InstalledVersions::getPrettyVersion('idct/php-nats-jetstream-client'),
    implode("\n", $results),
);
