<?php

/**
 * Shared setup for the examples: the autoloader, the server they run against, a message class, and a way to
 * remove the stream an example created. Not an example itself (the runner skips files starting with "_").
 *
 * The examples use the transport directly, outside a Symfony application, so that each one shows a single
 * behaviour end to end. In an application the same DSN and options go under framework.messenger.transports.
 *
 * NATS_DSN points them at a server, as "nats-jetstream://[user:password@]host:port". The default is the test
 * server `composer nats:start` runs.
 *
 * The examples that need a JetStream cluster run against the test cluster `composer nats:start` runs as well
 * (EXAMPLE_CLUSTER below), whatever NATS_DSN says, and the ones that take a server away stop and start its test
 * container with docker. Without the cluster or docker they print SKIP.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Psr\Log\AbstractLogger;

final class ExampleMessage
{
    public function __construct(public readonly string $text)
    {
    }
}

/**
 * The DSN of a transport on its own stream, so that examples do not see each other's messages.
 */
function exampleDsn(string $stream, string $query = ''): string
{
    $server = rtrim(getenv('NATS_DSN') ?: 'nats-jetstream://admin:password@127.0.0.1:4222', '/');

    return sprintf('%s/%s/%s.messages%s', $server, $stream, $stream, $query === '' ? '' : '?' . $query);
}

/**
 * A unique stream name for one run of an example.
 */
function exampleStream(string $example): string
{
    return 'ex_' . $example . '_' . bin2hex(random_bytes(3));
}

/**
 * Runs $use with a JetStream context of its own, on a client built from the example's DSN.
 *
 * @template T
 * @param \Closure(JetStreamContext): T $use
 * @return T
 */
function withJetStream(string $stream, \Closure $use): mixed
{
    $client = (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream))->client;
    $client->connect()->await();

    try {
        return $use($client->jetStream());
    } finally {
        $client->disconnect()->await();
    }
}

/**
 * How many messages the stream stores, whether or not any consumer has seen them.
 */
function storedMessages(string $stream): int
{
    return withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): int {
        $state = $jetStream->getStream($stream)->await()->raw['state'] ?? [];

        return is_array($state) && is_int($state['messages'] ?? null) ? $state['messages'] : 0;
    });
}

/**
 * Removes the stream an example created, leaving the server as it found it.
 */
function deleteExampleStream(string $stream): void
{
    withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): void {
        $jetStream->deleteStream($stream)->await();
    });
}

/**
 * A PSR-3 logger that keeps what it is given, so an example can show what the NATS client logged. In an
 * application the bundle hands the client the application's `logger` instead (README, "Logging").
 */
final class ExampleLogger extends AbstractLogger
{
    /** @var list<string> "level: message", in the order they were logged */
    public array $lines = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = sprintf('%s: %s', is_string($level) ? $level : 'log', $message);
    }

    /** Whether a line containing $text was logged. */
    public function logged(string $text): bool
    {
        foreach ($this->lines as $line) {
            if (str_contains($line, $text)) {
                return true;
            }
        }

        return false;
    }

    /** Prints what was logged, one indented line each, after an example's OK line. */
    public function printLines(): void
    {
        foreach ($this->lines as $line) {
            echo '    ', $line, "\n";
        }
    }
}

/**
 * The servers of the test cluster `composer nats:start` runs (tests/nats/docker-compose.yaml), by server name, which
 * is also the name of each one's container, with the port it is published on. They form the JetStream cluster
 * "east"; nats_cluster_1 and nats_cluster_2 carry the tag "disk:ssd", nats_cluster_3 the tag "disk:hdd".
 */
const EXAMPLE_CLUSTER = ['nats_cluster_1' => 4226, 'nats_cluster_2' => 4227, 'nats_cluster_3' => 4228];

/**
 * The DSN of a transport on its own stream that connects to one server of the test cluster. It names that one
 * server only: the client learns the others from it (INFO connect_urls) and, with `reconnect: true`, fails over
 * to them.
 */
function clusterDsn(string $server, string $stream, string $query = ''): string
{
    return sprintf('nats-jetstream://admin:password@127.0.0.1:%d/%s/%s.messages%s', EXAMPLE_CLUSTER[$server], $stream, $stream, $query === '' ? '' : '?' . $query);
}

/**
 * Runs $use with a JetStream context of its own, on a client connected to one server of the test cluster.
 *
 * @template T
 * @param \Closure(JetStreamContext): T $use
 * @return T
 */
function withClusterJetStream(string $server, \Closure $use): mixed
{
    $client = (new NatsTransportConfigurationBuilder())->build(clusterDsn($server, 'unused'))->client;
    $client->connect()->await();

    try {
        return $use($client->jetStream());
    } finally {
        $client->disconnect()->await();
    }
}

/**
 * What the cluster reports about a stream (`nats stream info`): its configuration under "config", and under
 * "cluster" the server that leads it and the other replicas. Asked through any server that is up.
 *
 * @return array<string, mixed>
 */
function clusterStreamInfo(string $stream, string $server = 'nats_cluster_1'): array
{
    return withClusterJetStream($server, static fn (JetStreamContext $jetStream): array => $jetStream->getStream($stream)->await()->raw);
}

/**
 * The servers that hold a replica of a stream, the leader first.
 *
 * @param array<string, mixed> $info What {@see clusterStreamInfo()} returned.
 * @return list<string>
 */
function streamPeers(array $info): array
{
    $cluster = is_array($info['cluster'] ?? null) ? $info['cluster'] : [];
    $peers = is_string($cluster['leader'] ?? null) ? [$cluster['leader']] : [];
    foreach (is_array($cluster['replicas'] ?? null) ? $cluster['replicas'] : [] as $replica) {
        if (is_array($replica) && is_string($replica['name'] ?? null)) {
            $peers[] = $replica['name'];
        }
    }

    return $peers;
}

/**
 * Whether every replica of a stream has a leader and has caught up with it, so the stream can take the next change.
 *
 * @param array<string, mixed> $info What {@see clusterStreamInfo()} returned.
 */
function streamIsCurrent(array $info): bool
{
    $cluster = is_array($info['cluster'] ?? null) ? $info['cluster'] : [];
    if (!is_string($cluster['leader'] ?? null) || isset($cluster['desired'])) {
        return false;
    }
    foreach (is_array($cluster['replicas'] ?? null) ? $cluster['replicas'] : [] as $replica) {
        if (!is_array($replica) || ($replica['current'] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

/**
 * Calls $check every 250 ms until it returns true; fails with $what once $seconds have passed.
 *
 * @param \Closure(): bool $check
 */
function waitUntil(string $what, float $seconds, \Closure $check): void
{
    $deadline = microtime(true) + $seconds;
    while (true) {
        try {
            if ($check()) {
                return;
            }
            $error = '';
        } catch (\Throwable $e) {
            $error = ': ' . $e->getMessage();
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException(sprintf('gave up after %.0f s waiting for %s%s', $seconds, $what, $error));
        }
        usleep(250000);
    }
}

/**
 * Exits with SKIP when the test cluster is not running, and otherwise waits until every one of its servers
 * answers JetStream requests (a cluster that just started needs a few seconds to elect its leader).
 */
function requireTestCluster(string $example): void
{
    $up = 0;
    foreach (EXAMPLE_CLUSTER as $port) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 1.0);
        if ($socket !== false) {
            fclose($socket);
            $up++;
        }
    }
    if ($up === 0) {
        printf("SKIP %s: needs the test cluster on ports %s (composer nats:start)\n", $example, implode(', ', EXAMPLE_CLUSTER));
        exit(0);
    }

    foreach (array_keys(EXAMPLE_CLUSTER) as $server) {
        waitForClusterServer($server);
    }
}

/**
 * Whether the server behind $jetStream answers a JetStream request; throws when it does not.
 */
function answersJetStream(JetStreamContext $jetStream): bool
{
    $jetStream->accountInfo()->await();

    return true;
}

/**
 * Waits until one server of the test cluster answers JetStream requests, after it started or rejoined.
 */
function waitForClusterServer(string $server): void
{
    waitUntil($server . ' to answer JetStream requests', 60, static fn (): bool => withClusterJetStream($server, answersJetStream(...)));
}

/**
 * Exits with SKIP unless docker can stop and start the running test container $container, as the examples that
 * take a server away do.
 */
function requireDockerFor(string $example, string $container): void
{
    exec('docker inspect --format {{.State.Running}} ' . escapeshellarg($container) . ' 2>/dev/null', $output, $status);
    if ($status !== 0 || ($output[0] ?? '') !== 'true') {
        printf("SKIP %s: needs docker and the running test container %s (composer nats:start)\n", $example, $container);
        exit(0);
    }
}

/**
 * Runs a docker command and waits for it, failing when it fails.
 */
function docker(string ...$arguments): void
{
    exec('docker ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('docker %s failed: %s', implode(' ', $arguments), implode("\n", $output)));
    }
}
