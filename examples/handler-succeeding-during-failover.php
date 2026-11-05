<?php

/**
 * A handler succeeding during a failover - in both retry modes, the ACK of a message handled while the worker's server
 * went away reaches the cluster through another server, and the message is not handled again.
 *
 * Runs Symfony's Worker, as `messenger:consume` does, against a stream with three replicas on the test cluster, once
 * per `retry_handler`. The worker's DSN names the server that leads the stream, with `reconnect: true`. The handler
 * stops that server with `docker stop`, as a deploy or a rolling restart does, and then succeeds. The server closes
 * the connection on its way out, and the client, which reads the socket only while an operation waits for a reply,
 * has not read that close when the worker ACKs the message. Since client 2.28.0 the client sees the close before it
 * writes the ACK, keeps the ACK for the connection its recovery makes on another server of the cluster, and sends it
 * there. With client 2.27 the closed socket took the ACK, no server received it, and NATS delivered the message again
 * after `ack_wait`, to be handled twice. The retry mode makes no difference to an ACK; the example runs both to show
 * it.
 *
 * Mirrors the README "A Handler Failing During the Loss" section (its last paragraphs). Needs the test cluster and
 * docker (composer nats:start). Run: php examples/handler-succeeding-during-failover.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Composer\InstalledVersions;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

requireTestCluster('handler-succeeding-during-failover');
foreach (array_keys(EXAMPLE_CLUSTER) as $server) {
    requireDockerFor('handler-succeeding-during-failover', $server);
}

$results = [];
foreach (['symfony', 'nats'] as $retryHandler) {
    $stream = exampleStream('handler_success_' . $retryHandler);
    // ack_wait 30 s: an ACK lost on the way would leave the message pending until then, past the worker's run.
    $query = 'stream_replicas=3&retry_handler=' . $retryHandler . '&ack_wait=30';

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
        $worker->send(new Envelope(new ExampleMessage('invoice 9')));

        // The handler stops the worker's server and then does its work.
        $handled = [];
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExampleMessage::class => [static function (ExampleMessage $message) use (&$handled, &$stopped, $leader): void {
                if ($handled === []) {
                    $stopped = $leader;
                    docker('stop', $leader);
                }
                $handled[] = microtime(true);
            }],
        ]))]);

        // What `messenger:consume` wires up, with a worker that pulls on for 3 s after the message was handled, so a
        // delivery of it again would be seen, or stops after 15 s.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(15));
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$handled): void {
            if ($handled !== [] && microtime(true) - $handled[0] > 3) {
                $event->getWorker()->stop();
            }
        });
        (new Worker(['nats' => $worker], $bus, $dispatcher))->run(['sleep' => 100000]);

        $info = clusterStreamInfo($stream, $leader === 'nats_cluster_1' ? 'nats_cluster_2' : 'nats_cluster_1');
        $newLeader = streamPeers($info)[0] ?? 'none';
        $consumer = withClusterJetStream($newLeader === 'none' ? 'nats_cluster_1' : $newLeader, static fn (JetStreamContext $jetStream): array => $jetStream->getConsumer($stream, 'client')->await()->raw);

        if (count($handled) !== 1 || ($consumer['num_ack_pending'] ?? null) !== 0 || ($consumer['num_pending'] ?? null) !== 0 || !$logger->logged('Reconnected')) {
            throw new RuntimeException(sprintf(
                'retry_handler=%s: expected the message handled once and its ACK to reach the cluster through another server, after a logged reconnect; got %d handling(s), %s pending acknowledgement, %s pending delivery (client %s), log: %s',
                $retryHandler,
                count($handled),
                var_export($consumer['num_ack_pending'] ?? null, true),
                var_export($consumer['num_pending'] ?? null, true),
                InstalledVersions::getPrettyVersion('idct/php-nats-jetstream-client'),
                implode(' | ', $logger->lines),
            ));
        }

        $results[] = sprintf(
            '  retry_handler=%s: the handler stopped %s and succeeded; the ACK reached the cluster through another server, nothing is pending, and the message was handled once; %s leads the stream now; the client logged:',
            $retryHandler,
            $leader,
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
    "OK handler-succeeding-during-failover: in both retry modes the ACK of a message handled while the worker's server stopped reached the cluster (ack_wait 30 s, client %s):\n%s\n",
    InstalledVersions::getPrettyVersion('idct/php-nats-jetstream-client'),
    implode("\n", $results),
);
