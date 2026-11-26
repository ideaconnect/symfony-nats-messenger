<?php

/**
 * Stream metadata - `stream_metadata` sets entries in the stream's metadata, and only those: what another tool
 * wrote there stays, and so do the server's own entries.
 *
 * On the test server the example
 *
 * 1. creates a stream with the entries `group` and `shard`;
 * 2. has another tool add `owner` to the stream, as an operator's tooling would with `nats stream edit`;
 * 3. runs the setup again without the option, next to a new `stream_max_age`: the metadata stays as it is;
 * 4. runs it with a new value for `group` and a new entry `team`: both are set, `shard` and `owner` stay, and
 *    the entries NATS writes itself (`_nats.ver` and others, from 2.11 on) are as they were;
 * 5. runs it with `owner` set to null: that entry is removed, and the others stay;
 * 6. is refused an entry whose name starts with `_nats.`, when the transport is built.
 *
 * On the test cluster it then creates a stream with three replicas and the entry `group`, adds `team` through
 * another server and removes `group` through the third: each of the three servers reports `team` alone.
 *
 * A server before 2.10 has no stream metadata and would drop the option, so there the setup is refused, which is
 * all the example checks on such a server.
 *
 * Mirrors the README "Stream Metadata" section. Needs the test server and the test cluster (composer nats:start).
 * Run: php examples/stream-metadata.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

requireTestCluster('stream-metadata');

/**
 * The metadata of a stream config, split into the entries NATS writes itself and all others, each sorted by name.
 *
 * @param array<mixed> $config
 * @return array{own: array<string, string>, servers: array<string, string>}
 */
function metadataOf(array $config): array
{
    $own = [];
    $servers = [];
    foreach (is_array($config['metadata'] ?? null) ? $config['metadata'] : [] as $name => $value) {
        if (str_starts_with((string) $name, '_nats.')) {
            $servers[(string) $name] = (string) $value;
        } else {
            $own[(string) $name] = (string) $value;
        }
    }
    ksort($own);
    ksort($servers);

    return ['own' => $own, 'servers' => $servers];
}

function expectSame(string $what, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: expected %s, got %s', $what, json_encode($expected), json_encode($actual)));
    }
}

$stream = exampleStream('stream_metadata');

/** @param array<string, mixed> $options */
$setupWith = static function (array $options) use ($stream): void {
    $transport = new NatsTransport(exampleDsn($stream), $options, new PhpSerializer());
    try {
        $transport->setup();
    } finally {
        $transport->close();
    }
};

/** @return array<mixed> */
$liveConfig = static function () use ($stream): array {
    $config = withJetStream($stream, static fn (JetStreamContext $jetStream): array => $jetStream->getStream($stream)->await()->raw)['config'] ?? [];

    return is_array($config) ? $config : [];
};

$client = (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream))->client;
$client->connect()->await();
$version = $client->serverInfo()->version ?? '';
$client->disconnect()->await();

// A server before 2.10 would drop the metadata without a word, so the setup is refused there.
if (preg_match('/^v?(\d+)\.(\d+)/', $version, $release) === 1 && version_compare($release[1] . '.' . $release[2], '2.10', '<')) {
    $refusal = null;
    try {
        $setupWith(['stream_metadata' => ['group' => 'odds-papi']]);
    } catch (RuntimeException $exception) {
        $refusal = $exception->getMessage();
    }
    $created = withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): bool {
        try {
            $jetStream->getStream($stream)->await();

            return true;
        } catch (Throwable) {
            return false;
        }
    });
    if ($refusal === null || !str_contains($refusal, "'stream_metadata' option requires NATS Server >= 2.10") || $created) {
        throw new RuntimeException(sprintf('expected the setup to be refused and no stream on NATS %s; got %s, stream created: %s', $version, var_export($refusal, true), var_export($created, true)));
    }

    printf("OK stream-metadata: NATS %s has no stream metadata, so the setup was refused and no stream was created\n", $version);

    return;
}

try {
    // 1. A new stream gets the configured entries.
    $setupWith(['stream_metadata' => ['group' => 'odds-papi', 'shard' => 7]]);
    $created = metadataOf($liveConfig());
    expectSame('the entries of the new stream', ['group' => 'odds-papi', 'shard' => '7'], $created['own']);

    // 2. Another tool adds an entry of its own.
    withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream, $liveConfig): void {
        $config = $liveConfig();
        $metadata = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
        $config['metadata'] = (object) (['owner' => 'devops'] + $metadata);
        // The server reports an unset consumer_limits as what PHP decodes to an empty list, and takes an object.
        if (($config['consumer_limits'] ?? null) === []) {
            $config['consumer_limits'] = (object) [];
        }
        unset($config['name']);
        $jetStream->updateStream($stream, $config)->await();
    });

    // 3. A setup without the option changes what it is asked to, and leaves the metadata alone.
    $setupWith(['stream_max_age' => 3600]);
    $untouched = $liveConfig();
    expectSame('the entries after a setup without the option', ['group' => 'odds-papi', 'owner' => 'devops', 'shard' => '7'], metadataOf($untouched)['own']);
    expectSame('max_age after that setup', 3600 * 1_000_000_000, $untouched['max_age'] ?? null);

    // 4. A configured entry is set; the others stay, the server's own included.
    $setupWith(['stream_max_age' => 3600, 'stream_metadata' => ['group' => 'odds-v2', 'team' => 'core']]);
    $merged = metadataOf($liveConfig());
    expectSame('the entries after a setup that sets two', ['group' => 'odds-v2', 'owner' => 'devops', 'shard' => '7', 'team' => 'core'], $merged['own']);
    expectSame('the entries NATS writes itself', $created['servers'], $merged['servers']);

    // 5. A null value removes an entry; the others stay, the server's own included.
    $setupWith(['stream_max_age' => 3600, 'stream_metadata' => ['owner' => null]]);
    $removed = metadataOf($liveConfig());
    expectSame('the entries after a setup that removes one', ['group' => 'odds-v2', 'shard' => '7', 'team' => 'core'], $removed['own']);
    expectSame('the entries NATS writes itself after the removal', $created['servers'], $removed['servers']);

    // 6. The server's own names cannot be set.
    $refusal = null;
    try {
        (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream), ['stream_metadata' => ['_nats.ver' => '9.9.9']]);
    } catch (InvalidArgumentException $exception) {
        $refusal = $exception->getMessage();
    }
    expectSame('the refusal of a name NATS keeps for itself', 'The stream_metadata option cannot set "_nats.ver": NATS keeps the names starting with "_nats." for itself.', $refusal);
} finally {
    deleteExampleStream($stream);
}

// On the cluster: three replicas, set through one server and changed through another.
$clusterStream = exampleStream('stream_metadata_r3');
try {
    foreach (['nats_cluster_1' => ['group' => 'odds-papi'], 'nats_cluster_2' => ['team' => 'core'], 'nats_cluster_3' => ['group' => null]] as $server => $metadata) {
        $transport = new NatsTransport(clusterDsn($server, $clusterStream), ['stream_replicas' => 3, 'stream_metadata' => $metadata], new PhpSerializer());
        try {
            $transport->setup();
        } finally {
            $transport->close();
        }
    }
    waitUntil('every replica of the stream to be current', 60, static fn (): bool => streamIsCurrent(clusterStreamInfo($clusterStream)));

    foreach (array_keys(EXAMPLE_CLUSTER) as $server) {
        $config = clusterStreamInfo($clusterStream, $server)['config'] ?? [];
        expectSame("the entries $server reports", ['team' => 'core'], metadataOf(is_array($config) ? $config : [])['own']);
        expectSame("the replicas $server reports", 3, is_array($config) ? $config['num_replicas'] ?? null : null);
    }
} finally {
    withClusterJetStream('nats_cluster_1', static fn (JetStreamContext $jetStream) => $jetStream->deleteStream($clusterStream)->await());
}

printf(
    "OK stream-metadata: NATS %s; created with group and shard; an entry another tool added stayed through a setup without the option and through one that set group and team (%s), and went when it was given null (%s); NATS's own entries stayed (%s); a _nats. name was refused; on the cluster all three servers report the one entry left of a stream with three replicas\n",
    $version,
    json_encode($merged['own']),
    json_encode($removed['own']),
    $merged['servers'] === [] ? 'none on this server' : implode(', ', array_keys($merged['servers'])),
);
