<?php

/**
 * Retrying through a NAK, the edges - what `retry_via: nak` does with a delivery nobody settled, a message that
 * cannot be decoded, an older Symfony Messenger, a message that was published with a retry count, and the options
 * it does not combine with (5.9.0).
 *
 * 1. A delivery nobody settled, as a crashed worker leaves it, comes back when `ack_wait` has run out, and counts
 *    as a retry: the retry count is the count the message was published with plus its earlier deliveries.
 * 2. A message that cannot be decoded is TERMed at once with this transport's igbinary serializer. Symfony
 *    Messenger 8.1's own serializers return such a failure as a message instead, which goes through the retry
 *    strategy like any failure; with Messenger 7.4 and 8.0 they throw, and the message is TERMed at once too.
 * 3. Symfony Messenger 7.4.22, 8.1.10 and later acknowledge a message they sent for retry, and earlier releases
 *    reject it. The transport NAKs the delivery either way. The reject() is made by hand here, so that the
 *    example shows it with any Messenger installed.
 * 4. A message published with a retry count has made those retries already: one published with count 1 runs 3
 *    times with max_retries 3, at counts 1, 2 and 3.
 * 5. The builder refuses `retry_via` in nats mode, and with `nak` a `nak_delay` and a `backoff`, since both
 *    would change the delays Symfony's retry strategy computed.
 * 6. A consumer whose `max_deliver` the option does not say, here one another configuration's setup limited to 2
 *    while the option is unset: the transport reads the consumer's own limit and publishes the retry of its last
 *    delivery as a copy, and warns once. Up to 5.9.0 the option decided, that retry was NAKed, NATS redelivered
 *    nothing, and the message was gone after 2 runs with no failure-transport copy (5.9.1).
 *
 * Mirrors the README "Retrying through a NAK" section. Needs the test server (composer nats:start).
 * Run: php examples/retry-via-nak-edge-cases.php
 */

declare(strict_types=1);

require __DIR__ . '/_retry_via.php';

use Composer\InstalledVersions;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$lines = [];
$messenger = (string) InstalledVersions::getVersion('symfony/messenger');

// 1. A delivery nobody settled counts as a retry.
$stream = exampleStream('retry_via_edges');
$transport = retryTransport($stream, 'retry_via=nak&ack_wait=1');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('the worker crashed')));

    $first = receiveOne($transport, 5.0);
    $again = receiveOne($transport, 10.0);
    $transport->ack($again['envelope']);

    assertExpected('the retry count of the first delivery', 0, RedeliveryStamp::getRetryCountFromEnvelope($first['envelope']));
    assertExpected('the retry count of a delivery after one nobody settled', 1, RedeliveryStamp::getRetryCountFromEnvelope($again['envelope']));
    assertSecondsBetween('the redelivery of a delivery nobody settled (ack_wait 1 s)', $again['at'] - $first['at'], 0.95, 4.0);

    $lines[] = sprintf('a delivery nobody settled came back %.2f s later (ack_wait 1 s), with retry count 1', $again['at'] - $first['at']);
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 2. A message that cannot be decoded, first with the transport's own serializer, then with Symfony's.
$stream = exampleStream('retry_via_edges');
$transport = new NatsTransport(exampleDsn($stream, 'retry_via=nak'), ['max_batch_timeout' => 0.2]);
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    // A payload that is igbinary, but not an envelope: what another application's publisher could put on the subject.
    $payload = (string) igbinary_serialize('not an envelope');
    withJetStream($stream, static fn (JetStreamContext $jetStream): mixed => $jetStream->publish($stream . '.messages', $payload)->await());

    $thrown = null;
    try {
        iterator_to_array($transport->get(), false);
    } catch (MessageDecodingFailedException $failure) {
        $thrown = $failure;
    }
    $later = receiveFor($transport, 1.0);
    $advisories = $advisories();

    assertExpected('get() threw a MessageDecodingFailedException', true, $thrown !== null);
    assertExpected('deliveries after the one that could not be decoded', 0, count($later));
    assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);
    assertExpected('deliveries that wait for an acknowledgement', 0, consumerCounts($stream)['ackPending']);

    $lines[] = "a message the igbinary serializer could not decode was TERMed at once: get() threw, and it was not delivered again";
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

$stream = exampleStream('retry_via_edges');
$transport = retryTransport($stream, 'retry_via=nak');
try {
    $transport->setup();
    $advisories = consumerAdvisories($stream);
    withJetStream($stream, static fn (JetStreamContext $jetStream): mixed => $jetStream->publish($stream . '.messages', 'not a serialized envelope')->await());

    if (version_compare($messenger, '8.1.0', '>=')) {
        // PhpSerializer returns the failure as a message, and the worker fails it like any other.
        $run = runWorker(
            $transport,
            new MultiplierRetryStrategy(2, 200, 2, 0, 0.0),
            static function (): void {
            },
            static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
            quiet: 1.0,
        );
        $advisories = $advisories();

        assertExpected('the retry count of each run', [0, 1, 2], $run->retryCounts());
        assertExpected('willRetry() of each failure', [true, true, false], $run->willRetry);
        assertExpected('copies in the failure transport', 1, $run->failureCopies());
        assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);

        $lines[] = sprintf(
            "a payload Symfony's PhpSerializer could not decode went through the retry strategy, as Messenger 8.1 and later return a decode failure as a message: 3 runs, the retries %s later, then a TERM and 1 copy in the failure transport",
            secondsList($run->gaps()),
        );
    } else {
        $thrown = null;
        try {
            iterator_to_array($transport->get(), false);
        } catch (MessageDecodingFailedException $failure) {
            $thrown = $failure;
        }
        $later = receiveFor($transport, 1.0);
        $advisories = $advisories();

        assertExpected('get() threw a MessageDecodingFailedException', true, $thrown !== null);
        assertExpected('deliveries after the one that could not be decoded', 0, count($later));
        assertExpected('MSG_TERMINATED advisories', 1, $advisories['MSG_TERMINATED'] ?? 0);

        $lines[] = "a payload Symfony's PhpSerializer could not decode was TERMed at once too: before Messenger 8.1 it throws, where 8.1 and later return the failure as a message that goes through the retry strategy";
    }
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 3. The reject() of Symfony Messenger 7.4.21, 8.1.9 and earlier, made by hand.
$stream = exampleStream('retry_via_edges');
$transport = retryTransport($stream, 'retry_via=nak');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('rejected after its retry was sent')));

    // What the worker and the retry listener of those releases do with a failed message: send its retry copy,
    // with the delay the strategy computed, then reject the delivery.
    $received = asTheWorkerReceivedIt(receiveOne($transport, 5.0)['envelope']);
    $transport->send($received->with(new DelayStamp(300), new RedeliveryStamp(1)));
    $transport->reject($received);
    $rejectedAt = microtime(true);

    $again = receiveOne($transport, 10.0);
    $transport->ack($again['envelope']);

    assertSecondsBetween('the redelivery after a reject() (delay 300 ms)', $again['at'] - $rejectedAt, 0.29, 1.8);
    assertExpected('the retry count after a reject()', 1, RedeliveryStamp::getRetryCountFromEnvelope($again['envelope']));
    assertExpected('messages in the stream', 1, storedMessages($stream));

    $lines[] = sprintf('a delivery the worker rejected after it sent the retry, as Messenger 7.4.21, 8.1.9 and earlier do, was NAKed too: back %.2f s later (delay 300 ms), with retry count 1 and 1 message in the stream', $again['at'] - $rejectedAt);
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 4. A message published with a retry count.
$stream = exampleStream('retry_via_edges');
$transport = retryTransport($stream, 'retry_via=nak');
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('published with retry count 1'), [new RedeliveryStamp(1)]));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(3, 200, 2, 0, 0.0),
        static function (): void {
            throw new RuntimeException('the payment provider did not answer');
        },
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
    );

    assertExpected('the retry count of each run', [1, 2, 3], $run->retryCounts());
    assertExpected('willRetry() of each failure', [true, true, false], $run->willRetry);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());

    $lines[] = 'a message published with retry count 1 ran 3 times with max_retries 3, at retry counts 1, 2 and 3: its earlier deliveries are added to the count it carries';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// 5. What the builder refuses, with no connection at all.
$refused = static function (array $options, string $expected): void {
    try {
        new NatsTransport(exampleDsn(exampleStream('retry_via_edges')), $options, new PhpSerializer());
    } catch (InvalidArgumentException $refusal) {
        if (str_contains($refusal->getMessage(), $expected)) {
            return;
        }

        throw $refusal;
    }

    throw new RuntimeException(sprintf('the transport was built, though it should have said: %s', $expected));
};
$refused(['retry_handler' => 'nats', 'retry_via' => 'nak'], "The retry_via option needs retry_handler 'symfony'");
$refused(['retry_handler' => 'nats', 'retry_via' => 'copy'], "The retry_via option needs retry_handler 'symfony'");
$refused(['retry_via' => 'nak', 'nak_delay' => 2], "The nak_delay option has no effect with retry_via 'nak'");
$refused(['retry_via' => 'nak', 'backoff' => [1, 5], 'max_deliver' => 3], "The backoff option cannot be combined with retry_via 'nak'");
$lines[] = "the builder refused retry_via with retry_handler: nats, and nak_delay and backoff with retry_via: nak";

// 6. A consumer whose max_deliver the option does not say.
$stream = exampleStream('retry_via_edges');
$logger = new ExampleLogger();
$transport = retryTransport($stream, 'retry_via=nak', $logger);
try {
    $transport->setup();
    // Another configuration's setup on the same consumer, with max_deliver 2.
    $other = retryTransport($stream, 'retry_via=nak&max_deliver=2');
    $other->setup();
    $other->close();
    $transport->send(new Envelope(new ExampleMessage('the consumer allows 2 deliveries')));

    $run = runWorker(
        $transport,
        new MultiplierRetryStrategy(2, 200, 1, 0, 0.0),
        static function (): void {
            throw new RuntimeException('the payment provider did not answer');
        },
        static fn (WorkerRun $run): bool => in_array(false, $run->willRetry, true),
    );

    assertExpected('the retry count of each run', [0, 1, 2], $run->retryCounts());
    assertExpected('willRetry() of each failure', [true, true, false], $run->willRetry);
    assertExpected('copies in the failure transport', 1, $run->failureCopies());
    assertExpected(
        'the warning about the consumer\'s limit',
        1,
        count(array_filter($logger->lines, static fn (string $line): bool => str_contains($line, 'consumer "client" has max_deliver 2 and the max_deliver option is unset (no limit)'))),
    );

    $lines[] = 'a consumer limited to 2 by another setup, with the option unset: 3 runs and 1 failure copy, the retry of the second delivery published as a copy, and one warning';
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

printf("OK retry-via-nak-edge-cases: %d cases held (symfony/messenger %s):\n", count($lines), InstalledVersions::getPrettyVersion('symfony/messenger'));
foreach ($lines as $line) {
    echo '    ', $line, "\n";
}
