<?php

/**
 * Nats mode on a cluster - with `retry_handler: nats` and `max_deliver`, a message that keeps failing is NAKed until
 * `max_deliver`, and then NATS redelivers nothing and publishes MAX_DELIVERIES. In that mode the message left in the
 * stream is the only record of it, and NATS does not keep it on every stream (5.10.0, measured on nats-server 2.10.29
 * to 2.15.2, docs/symfony_retry_via.md):
 *
 * 1. On a stream with interest retention and three replicas, NATS deletes it: here within seconds of a later message
 *    being acknowledged, as the stream is new (on a stream that has been running, about two minutes later). setup()
 *    warns about such a stream, once.
 * 2. On a work-queue stream with three replicas it stays, on NATS 2.12.5 and later, and setup() says nothing.
 *
 * The test cluster runs the same NATS image as the other servers. Use `retry_via: nak` with a failure transport on a
 * stream like the first one (README, "Retry Handler Behavior").
 *
 * Needs the test cluster (composer nats:start). Run: php examples/nats-retry-on-a-cluster.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

requireTestCluster('nats-retry-on-a-cluster');

$server = 'nats_cluster_1';

/** How many messages the cluster stores in $stream. */
$storedOnTheCluster = static function (string $stream) use ($server): int {
    $state = clusterStreamInfo($stream, $server)['state'] ?? [];

    return is_array($state) && is_int($state['messages'] ?? null) ? $state['messages'] : -1;
};

/**
 * One message that keeps failing in nats mode with max_deliver 2 on a stream with $retention and three replicas: both
 * deliveries are rejected (NAKed), then three later messages are handled. Then the stream is watched for up to
 * $watch seconds, with a worker pulling, for the message to be deleted.
 *
 * @return array{warnings: list<string>, storedRightAfter: int, storedAtTheEnd: int, deletedAfter: float|null, version: string}
 */
$stoppedAtMaxDeliver = static function (string $retention, float $watch) use ($server, $storedOnTheCluster): array {
    $stream = exampleStream('nats_retry_cluster');
    $logger = new ExampleLogger();
    $transport = new NatsTransport(
        clusterDsn($server, $stream, 'retry_handler=nats&max_deliver=2&stream_replicas=3&stream_retention=' . $retention),
        ['max_batch_timeout' => 0.2],
        new PhpSerializer(),
        $logger,
    );
    try {
        $transport->setup();
        $version = (string) (new ReflectionProperty(NatsTransport::class, 'client'))->getValue($transport)->serverInfo()?->version;
        $transport->send(new Envelope(new ExampleMessage('keeps failing')));
        foreach (['h1', 'h2', 'h3'] as $text) {
            $transport->send(new Envelope(new ExampleMessage($text)));
        }

        // The worker: the failing message is rejected each time, as messenger:consume does in nats mode.
        $pump = static function (float $seconds) use ($transport): void {
            $deadline = microtime(true) + $seconds;
            while (microtime(true) < $deadline) {
                foreach ($transport->get() as $envelope) {
                    $message = $envelope->getMessage();
                    if ($message instanceof ExampleMessage && $message->text === 'keeps failing') {
                        $transport->reject($envelope);
                    } else {
                        $transport->ack($envelope);
                    }
                }
            }
        };
        $pump(2.0);
        $storedRightAfter = $storedOnTheCluster($stream);

        $start = microtime(true);
        $deletedAfter = null;
        while (microtime(true) - $start < $watch && $deletedAfter === null) {
            $pump(1.0);
            if ($storedOnTheCluster($stream) === 0) {
                $deletedAfter = microtime(true) - $start;
            }
        }

        return [
            'warnings' => array_values(array_filter($logger->lines, static fn (string $line): bool => str_starts_with($line, 'warning: retry_handler=nats'))),
            'storedRightAfter' => $storedRightAfter,
            'storedAtTheEnd' => $storedOnTheCluster($stream),
            'deletedAfter' => $deletedAfter,
            'version' => $version,
        ];
    } finally {
        $transport->close();
        withClusterJetStream($server, static fn (JetStreamContext $jetStream) => $jetStream->deleteStream($stream)->await());
    }
};

$interest = $stoppedAtMaxDeliver('interest', 30.0);
assertExpected('interest: the message right after its last delivery', 1, $interest['storedRightAfter']);
assertExpected('interest: setup() warned once', 1, count($interest['warnings']));
assertExpected('interest: the warning names the stream kind', true, str_contains($interest['warnings'][0] ?? '', 'from a stream with interest retention on a clustered server'));
if ($interest['deletedAfter'] === null) {
    throw new RuntimeException(sprintf('expected NATS %s to delete the message from the interest stream within 30 s; it holds %d', $interest['version'], $interest['storedAtTheEnd']));
}

$workQueue = $stoppedAtMaxDeliver('workqueue', 12.0);
$fixed = version_compare(preg_replace('/^v?(\d+\.\d+\.\d+).*$/', '$1', $workQueue['version']) ?? '', '2.12.5', '>=');
assertExpected('work-queue: setup() warned', $fixed ? 0 : 1, count($workQueue['warnings']));
assertExpected('work-queue: the message 12 s after its last delivery', $fixed ? 1 : 0, $workQueue['storedAtTheEnd']);

printf(
    "OK nats-retry-on-a-cluster: on NATS %s with three replicas, a message stopped at max_deliver in nats mode was deleted from the interest stream %.0f s after the watch began, and setup() had warned about it once:\n    %s\n  and on the work-queue stream it %s, %s\n",
    $interest['version'],
    $interest['deletedAfter'],
    substr($interest['warnings'][0], strlen('warning: ')),
    $fixed ? 'stayed for the 12 s watched' : 'was deleted, as before NATS 2.12.5',
    $fixed ? 'and setup() said nothing' : 'and setup() warned',
);
