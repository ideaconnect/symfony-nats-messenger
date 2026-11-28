<?php

/**
 * Retrying through a NAK when the consumer cannot be read - with `retry_via: nak`, a read of the consumer's
 * `max_deliver` that gets no answer, or that the server refuses, leaves the option to decide that retry, with one
 * warning, and the worker carries on (#127).
 *
 * The transport asks the consumer for its own `max_deliver` at the first retry that needs it, since the retry of the
 * last delivery `max_deliver` allows has to go out as a copy: NATS redelivers nothing after that delivery. Up to 5.10.1
 * a read that got no answer within `request_timeout`, or that failed on the connection, made the send of the retry
 * fail, which stopped the worker, and that last delivery was lost with no failure-transport copy.
 *
 * Runs Symfony's own Worker with its retry and failure-transport listeners, on a transport with `max_deliver: 2`,
 * `request_timeout: 1` and `ack_wait: 2`, and max_retries 3:
 *
 * 1. Behind a proxy that drops every consumer info request (tests/nats/consumer-info-proxy.php): a message that keeps
 *    failing runs 4 times, at retry counts 0 to 3, and reaches the failure transport, with one warning that the
 *    consumer could not be read. Up to 5.10.1 the worker stopped on a TimeoutException at the first retry.
 * 2. The same with `batching: 3`, the failing message first and two that succeed behind it in the batch: both are
 *    handled. Up to 5.10.1 each stop cost them a delivery without a handler run, and `max_deliver` ended them.
 * 3. As a user of the test server who may not ask for consumer info: the server refuses the read at once, the
 *    message runs 4 times and reaches the failure transport, and the warning names the permission to give.
 *
 * Mirrors the README "Retrying through a NAK" section. Needs the test server (composer nats:start); the third part needs
 * its user in tests/nats/nats.conf, so it is left out when NATS_DSN points elsewhere.
 * Run: php examples/retry-via-nak-consumer-info.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Composer\InstalledVersions;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

const OPTIONS = 'retry_via=nak&max_deliver=2&request_timeout=1&ack_wait=2';

/**
 * Starts the proxy that drops consumer info requests in front of the server the examples run against. The closure it
 * returns stops it, by its pid, and gives the subjects of the requests it dropped.
 *
 * @return array{int, \Closure(): list<string>} the port it listens on, and the closure
 */
function startConsumerInfoProxy(): array
{
    $server = parse_url(getenv('NATS_DSN') ?: 'nats-jetstream://admin:password@127.0.0.1:4222');
    $upstream = sprintf('%s:%d', $server['host'] ?? '127.0.0.1', $server['port'] ?? 4222);
    $process = proc_open([PHP_BINARY, __DIR__ . '/../tests/nats/consumer-info-proxy.php', $upstream, '120'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('the consumer info proxy did not start');
    }

    $listening = (string) fgets($pipes[1]);
    if (!preg_match('/^LISTENING (\d+)$/', trim($listening), $port)) {
        proc_terminate($process);
        throw new RuntimeException('the consumer info proxy did not start: ' . $listening . stream_get_contents($pipes[2]));
    }

    return [(int) $port[1], static function () use ($process, $pipes): array {
        // SIGTERM to its pid: proc_open() started PHP itself, without a shell in between.
        proc_terminate($process);
        $output = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        preg_match_all('/^DROPPED (\S+)$/m', $output, $dropped);

        return $dropped[1];
    }];
}

/** Sets up the stream and its consumer with the options through a transport that connects to the server itself. */
function setUpDirectly(string $stream, string $query): void
{
    $transport = retryTransport($stream, $query);
    try {
        $transport->setup();
    } finally {
        $transport->close();
    }
}

/** The DSN of a transport on $stream that connects through the proxy on $port, with the server's login. */
function proxiedDsn(int $port, string $stream, string $query): string
{
    $server = parse_url(getenv('NATS_DSN') ?: 'nats-jetstream://admin:password@127.0.0.1:4222');
    $login = isset($server['user']) ? $server['user'] . (isset($server['pass']) ? ':' . $server['pass'] : '') . '@' : '';

    return sprintf('%s://%s127.0.0.1:%d/%s/%s.messages?%s', $server['scheme'] ?? 'nats-jetstream', $login, $port, $stream, $stream, $query);
}

/** The warnings that say the consumer could not be read for its max_deliver. */
function consumerReadWarnings(ExampleLogger $logger): array
{
    return array_values(array_filter(warningsLogged($logger), static fn (string $line): bool => str_contains($line, 'could not be read for its max_deliver')));
}

/**
 * Runs a message that keeps failing through Symfony's Worker on $transport, and checks that it ran 4 times and
 * reached the failure transport, with the original and the copy of its last delivery in the stream.
 */
function runAMessageThatKeepsFailing(NatsTransport $transport, string $stream): WorkerRun
{
    $transport->send(new Envelope(new ExampleMessage('keeps failing')));
    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(3, 100, 1, 0, 0.0),
        static function (): void {
            throw new RuntimeException('the payment provider did not answer');
        },
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
        quiet: 0.5,
    );

    assertExpected('the retry count of each run', [0, 1, 2, 3], $run->retryCounts());
    assertExpected('willRetry() of each failure', [true, true, true, false], $run->willRetry);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());
    assertExpected('messages in the stream', 2, storedMessages($stream));

    return $run;
}

$lines = [];

// 1. Every consumer info request goes unanswered.
$stream = exampleStream('retry_via_info');
[$port, $stopProxy] = startConsumerInfoProxy();
$logger = new ExampleLogger();
$transport = new NatsTransport(proxiedDsn($port, $stream, OPTIONS), ['max_batch_timeout' => 0.2], new PhpSerializer(), $logger);
try {
    setUpDirectly($stream, OPTIONS);
    $started = microtime(true);
    runAMessageThatKeepsFailing($transport, $stream);
    $seconds = microtime(true) - $started;
    $dropped = $stopProxy();
    $stopProxy = null;

    assertExpected('a consumer info request the proxy dropped', true, $dropped !== []);
    assertExpected('what the proxy dropped', [sprintf('$JS.API.CONSUMER.INFO.%s.client', $stream)], array_values(array_unique($dropped)));
    $warnings = consumerReadWarnings($logger);
    assertExpected('warnings that the consumer could not be read', 1, count($warnings));
    assertExpected(
        'the warning that the option decided and the consumer is asked again',
        true,
        str_contains($warnings[0], 'so the max_deliver option (2) tells which delivery is the last one. It is asked again at the next retry.'),
    );

    $lines[] = sprintf(
        'behind a proxy that dropped %d consumer info requests, a message that keeps failing ran 4 times, at retry counts 0 to 3, in %.1f s, with 1 copy in the failure transport and one warning that the max_deliver option decided',
        count($dropped),
        $seconds,
    );
} finally {
    $transport->close();
    if ($stopProxy !== null) {
        $stopProxy();
    }
    deleteExampleStream($stream);
}

// 2. The same, with the failing message first in a batch of three.
$stream = exampleStream('retry_via_info');
[$port, $stopProxy] = startConsumerInfoProxy();
$transport = new NatsTransport(proxiedDsn($port, $stream, OPTIONS . '&batching=3'), ['max_batch_timeout' => 0.2], new PhpSerializer(), new ExampleLogger());
try {
    setUpDirectly($stream, OPTIONS . '&batching=3');
    foreach (['keeps failing', 'succeeds 1', 'succeeds 2'] as $text) {
        $transport->send(new Envelope(new ExampleMessage($text)));
    }

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(3, 100, 1, 0, 0.0),
        static function (ExampleMessage $message): void {
            if ($message->text === 'keeps failing') {
                throw new RuntimeException('the payment provider did not answer');
            }
        },
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true) && count($run->handled) === 2,
        quiet: 0.5,
    );
    $dropped = $stopProxy();
    $stopProxy = null;

    assertExpected('the messages handled', ['succeeds 1', 'succeeds 2'], array_map(static fn (array $handled): string => $handled['text'], $run->handled));
    assertExpected('willRetry() of each failure', [true, true, true, false], $run->willRetry);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());
    assertExpected('a consumer info request the proxy dropped', true, $dropped !== []);

    $lines[] = sprintf(
        'with batching 3 behind the same proxy (%d requests dropped), the two messages behind the failing one in the batch were handled once each, and the failing one reached the failure transport',
        count($dropped),
    );
} finally {
    $transport->close();
    if ($stopProxy !== null) {
        $stopProxy();
    }
    deleteExampleStream($stream);
}

// 3. A user who may not ask for consumer info, on the test server.
if (getenv('NATS_DSN')) {
    $lines[] = 'the user who may not ask for consumer info is a user of the test server (tests/nats/nats.conf): left out, as NATS_DSN points elsewhere';
} else {
    $stream = exampleStream('retry_via_info');
    $logger = new ExampleLogger();
    $transport = new NatsTransport(
        sprintf('nats-jetstream://no_consumer_info:password@127.0.0.1:4222/%s/%s.messages?%s', $stream, $stream, OPTIONS),
        ['max_batch_timeout' => 0.2],
        new PhpSerializer(),
        $logger,
    );
    try {
        setUpDirectly($stream, OPTIONS);
        runAMessageThatKeepsFailing($transport, $stream);

        $warnings = consumerReadWarnings($logger);
        assertExpected('warnings that the consumer could not be read', 1, count($warnings));
        assertExpected(
            'the warning that names the permission',
            true,
            str_contains($warnings[0], sprintf('so the max_deliver option (2) tells which delivery is the last one. Let this user ask for consumer info ($JS.API.CONSUMER.INFO.%s.client).', $stream)),
        );

        $lines[] = 'as a user who may not ask for consumer info, the message ran 4 times and reached the failure transport, and the one warning names $JS.API.CONSUMER.INFO.<stream>.client';
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }
}

printf("OK retry-via-nak-consumer-info: a consumer that could not be read did not stop the worker (symfony/messenger %s):\n", InstalledVersions::getPrettyVersion('symfony/messenger'));
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
