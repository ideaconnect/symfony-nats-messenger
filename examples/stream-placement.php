<?php

/**
 * Stream placement - pin a stream to a cluster and to tagged servers, change one half of a placement, and stage
 * a move that comes with a change of the replica count (5.6.0).
 *
 * The test cluster "east" has three servers: nats_cluster_1 and nats_cluster_2 carry the tag "disk:ssd",
 * nats_cluster_3 carries "disk:hdd" (tests/nats/nats-cluster-N.conf, `server_tags`). The example
 *
 * 1. creates a stream with two replicas on the "disk:ssd" servers and sends three messages to it;
 * 2. sets `stream_placement_cluster` alone, next to a new `stream_max_age`: the update replaces the cluster
 *    field and keeps the live tags, so the replicas stay where they are (an update that sent the cluster alone
 *    would drop the tags, and the server could then move the replicas to any server of the cluster);
 * 3. asks for one replica on the "disk:hdd" server in one go: NATS refuses to move and scale a stream in one
 *    update, and setup() fails with the server's message and the steps to stage it, leaving the stream as it was;
 * 4. stages it: first the replica count with the placement as it was, then, once every replica is current, the
 *    new tags. The stream moves to nats_cluster_3 in the background, its three messages with it.
 *
 * Mirrors the README "Stream Placement" section. Needs the test cluster (composer nats:start).
 * Run: php examples/stream-placement.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

requireTestCluster('stream-placement');

$stream = exampleStream('stream_placement');

/**
 * Runs messenger:setup-transports for a transport with these options, as a deploy with them would.
 *
 * @param array<string, mixed> $options
 */
$setupWith = static function (array $options) use ($stream): NatsTransport {
    $transport = new NatsTransport(clusterDsn('nats_cluster_1', $stream), $options, new PhpSerializer());
    $transport->setup();

    return $transport;
};

/** The servers holding the stream once it settled, in name order. */
$settledPeers = static function () use ($stream): array {
    waitUntil('every replica of the stream to be current', 60, static fn (): bool => streamIsCurrent(clusterStreamInfo($stream)));
    $peers = streamPeers(clusterStreamInfo($stream));
    sort($peers);

    return $peers;
};

try {
    // 1. Two replicas, on servers tagged "disk:ssd" only.
    $transport = $setupWith(['stream_replicas' => 2, 'stream_placement_tags' => ['disk:ssd']]);
    foreach (['first', 'second', 'third'] as $text) {
        $transport->send(new Envelope(new ExampleMessage($text)));
    }
    $transport->close();
    $created = $settledPeers();

    // 2. The cluster alone: its own field changes, the live tags stay.
    $setupWith(['stream_replicas' => 2, 'stream_placement_cluster' => 'east', 'stream_max_age' => 3600])->close();
    $config = clusterStreamInfo($stream)['config'] ?? [];
    $merged = is_array($config) ? $config['placement'] ?? null : null;
    $maxAge = is_array($config) ? $config['max_age'] ?? null : null;
    $afterMerge = $settledPeers();

    // 3. A move (new tags) and a scale (new replica count) in one update: refused.
    $refusal = null;
    try {
        $setupWith(['stream_replicas' => 1, 'stream_placement_cluster' => 'east', 'stream_placement_tags' => ['disk:hdd']])->close();
    } catch (RuntimeException $exception) {
        $refusal = $exception->getMessage();
    }
    $afterRefusal = $settledPeers();

    // 4. Staged: the replica count first, with the placement as it was ...
    $setupWith(['stream_replicas' => 1, 'stream_placement_cluster' => 'east', 'stream_placement_tags' => ['disk:ssd']])->close();
    $scaled = $settledPeers();
    // ... then, once the replicas are current, the placement. The server moves the stream in the background.
    $transport = $setupWith(['stream_replicas' => 1, 'stream_placement_cluster' => 'east', 'stream_placement_tags' => ['disk:hdd']]);
    waitUntil('the stream to move to nats_cluster_3', 60, static fn (): bool => streamIsCurrent($moved = clusterStreamInfo($stream)) && streamPeers($moved) === ['nats_cluster_3']);
    $waiting = $transport->getMessageCount();
    $transport->close();

    $ssd = ['nats_cluster_1', 'nats_cluster_2'];
    if (
        $created !== $ssd
        || $merged !== ['cluster' => 'east', 'tags' => ['disk:ssd']] || $maxAge !== 3600 * 1_000_000_000 || $afterMerge !== $ssd
        || $refusal === null || !str_contains($refusal, 'can not move and scale') || !str_contains($refusal, 'Stage the two changes') || $afterRefusal !== $ssd
        || count($scaled) !== 1 || array_diff($scaled, $ssd) !== []
        || $waiting !== 3
    ) {
        throw new RuntimeException(sprintf(
            'unexpected placement: created on [%s], merged %s (max_age %s) on [%s], refusal %s, [%s] after it, scaled to [%s], %d waiting after the move',
            implode(', ', $created),
            json_encode($merged),
            var_export($maxAge, true),
            implode(', ', $afterMerge),
            var_export($refusal, true),
            implode(', ', $afterRefusal),
            implode(', ', $scaled),
            $waiting,
        ));
    }

    printf("OK stream-placement: 2 replicas on the ssd servers %s; the cluster set alone kept the tags (%s); move and scale in one update refused; staged, the stream went to 1 replica on %s, then moved to nats_cluster_3 with its %d messages\n", implode(' and ', $created), json_encode($merged), $scaled[0], $waiting);
} finally {
    withClusterJetStream('nats_cluster_1', static fn (JetStreamContext $jetStream) => $jetStream->deleteStream($stream)->await());
}
