<?php

/**
 * Cluster failover - with `reconnect: true` a worker connected to one server of a JetStream cluster carries on
 * through another one when that server stops (5.7.0).
 *
 * The stream has three replicas on the test cluster, so it stays available while one server is away. The worker's
 * DSN names a single server, the one that leads the stream; the client learns the other two from it (the
 * server's INFO lists them as connect_urls). The example consumes half of what was sent and stops that server
 * with `docker stop`, gracefully, as a deploy or a rolling restart does. The worker goes on calling get() as
 * `messenger:consume` does: the client notices the lost connection and recovers it on a server it was never
 * configured with, another server leads the stream, and the worker receives the rest, and one more message it
 * sends after the failover, without an exception. The client reports the loss and the recovery through the
 * logger the transport was given.
 *
 * Without `reconnect: true` the get() that runs into the lost connection fails with the client's
 * ConnectionException ("Reconnect is disabled") and `messenger:consume` exits. The worker its supervisor starts in
 * its place knows only the server its DSN names, so it cannot connect until that server is back.
 *
 * Mirrors the README "Automatic Reconnect" and "Logging" sections. Needs the test cluster and docker
 * (composer nats:start). Run: php examples/cluster-failover.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

requireTestCluster('cluster-failover');
foreach (array_keys(EXAMPLE_CLUSTER) as $server) {
    requireDockerFor('cluster-failover', $server);
}

$stream = exampleStream('cluster_failover');
$replicas = 'stream_replicas=3';

// messenger:setup-transports, through any server of the cluster.
$setup = new NatsTransport(clusterDsn('nats_cluster_1', $stream, $replicas), [], new PhpSerializer());
$setup->setup();
$setup->close();

// The worker connects to the server that leads the stream, the one stopped below.
$leader = streamPeers(clusterStreamInfo($stream))[0];
$logger = new ExampleLogger();
$worker = new NatsTransport(
    clusterDsn($leader, $stream, $replicas),
    ['reconnect' => true, 'max_batch_timeout' => 1],
    new PhpSerializer(),
    $logger,
);

/** Receives and acknowledges until $count messages arrived, counting the pulls that ended empty. */
$emptyPulls = 0;
$consume = static function (int $count) use ($worker, &$emptyPulls): array {
    $received = [];
    $deadline = microtime(true) + 60;
    while (count($received) < $count) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(sprintf('expected %d messages within 60 s, got [%s]', $count, implode(', ', $received)));
        }
        $envelopes = iterator_to_array($worker->get(), false);
        $emptyPulls += $envelopes === [] ? 1 : 0;
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            assert($message instanceof ExampleMessage);
            $received[] = $message->text;
            $worker->ack($envelope);
        }
    }

    return $received;
};

$stopped = null;
try {
    foreach (['order 1', 'order 2', 'order 3', 'order 4'] as $text) {
        $worker->send(new Envelope(new ExampleMessage($text)));
    }
    $before = $consume(2);

    // The server the worker is connected to goes away, between two of the worker's operations.
    $stopped = $leader;
    docker('stop', $stopped);
    $stoppedAt = microtime(true);

    $emptyPulls = 0;
    $after = $consume(2);
    $failoverSeconds = microtime(true) - $stoppedAt;

    // The worker's connection is on another server now, and sending goes through it too.
    $worker->send(new Envelope(new ExampleMessage('order 5')));
    $after = [...$after, ...$consume(1)];

    $info = clusterStreamInfo($stream, $stopped === 'nats_cluster_1' ? 'nats_cluster_2' : 'nats_cluster_1');
    $newLeader = streamPeers($info)[0] ?? 'none';

    if ($before !== ['order 1', 'order 2'] || $after !== ['order 3', 'order 4', 'order 5'] || $newLeader === $stopped || !$logger->logged('Reconnected')) {
        throw new RuntimeException(sprintf('expected orders 1-2 before and 3-5 after the failover, a new leader and a logged reconnect; got [%s], [%s], leader %s, log: %s', implode(', ', $before), implode(', ', $after), $newLeader, implode(' | ', $logger->lines)));
    }

    printf(
        "OK cluster-failover: %s stopped after 2 of 4 orders; within %.1f s the worker received the other 2 through another server, and one sent after the failover (%d empty pulls, no exception); %s leads the stream now; the client logged %d lines:\n",
        $stopped,
        $failoverSeconds,
        $emptyPulls,
        $newLeader,
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
