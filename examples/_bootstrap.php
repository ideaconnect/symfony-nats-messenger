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
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;

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
