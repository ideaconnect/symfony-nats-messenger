<?php

/**
 * What a custom serializer's decode() throws other than a MessageDecodingFailedException, as README, "Creating
 * Custom Serializers" describes: nothing settles the message, so it comes back after ack_wait to whichever get()
 * call runs next, which throws again, for ever without max_deliver. The serializer here encodes through Symfony's
 * PhpSerializer and always throws a \RuntimeException from decode(), as a schema registry or another service a
 * real decoder depends on being away would.
 *
 * (a) Without max_deliver: two get() calls an ack_wait apart each throw the exception, and the delivery is left
 *     pending acknowledgement.
 * (b) With max_deliver: 2: get() throws the exception twice over a few seconds and then returns nothing. NATS
 *     stops redelivering the message and publishes the MAX_DELIVERIES advisory, naming the stream sequence and
 *     the deliveries; nothing is left pending, and the stream keeps the message.
 *
 * Mirrors the README "Creating Custom Serializers" paragraph "What decode() must throw". Needs the test server
 * (composer nats:start). Run: php examples/serializer-exception.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_retry_via.php';

use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** A serializer of the application's own: encodes normally, and its decode() always fails on its own services. */
$errorSerializer = new class () implements SerializerInterface {
    public function encode(Envelope $envelope): array
    {
        return (new PhpSerializer())->encode($envelope);
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        throw new RuntimeException('schema registry: connection refused');
    }
};

/** @return list<?Throwable> what each get() call threw, null when it returned nothing */
$pullFor = static function (NatsTransport $transport, float $seconds) use (&$pullFor): array {
    $thrown = [];
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        try {
            iterator_to_array($transport->get(), false);
        } catch (\Throwable $e) {
            $thrown[] = $e;
        }
    }

    return $thrown;
};

// (a) No max_deliver: every get() an ack_wait apart throws the serializer's exception, and nothing settles the
// delivery.
$stream = exampleStream('serializer_exception');
$transport = new NatsTransport(exampleDsn($stream, 'ack_wait=1'), ['max_batch_timeout' => 0.2], $errorSerializer);
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('invoice 11')));

    $thrownAt0 = $pullFor($transport, 0.5);
    sleep(1); // past ack_wait, so the next get() sees the same message redelivered.
    $thrownAt1 = $pullFor($transport, 0.5);

    $counts = consumerCounts($stream);
    $stored = storedMessages($stream);

    if (
        count($thrownAt0) < 1 || count($thrownAt1) < 1
        || !str_contains($thrownAt0[0]->getMessage(), 'schema registry: connection refused')
        || !str_contains($thrownAt1[0]->getMessage(), 'schema registry: connection refused')
        || $counts['ackPending'] !== 1 || $stored !== 1
    ) {
        throw new RuntimeException(sprintf(
            'expected two get() calls an ack_wait apart to both throw the serializer\'s exception, with 1 pending acknowledgement and 1 message stored; got %d and %d throws, consumer %s, stored %d',
            count($thrownAt0),
            count($thrownAt1),
            json_encode($counts),
            $stored,
        ));
    }

    echo "OK serializer-exception: without max_deliver, two get() calls an ack_wait apart both threw the serializer's exception (\"schema registry: connection refused\"); the delivery is still pending acknowledgement, and the stream holds its 1 message\n";
} finally {
    $transport->close();
    deleteExampleStream($stream);
}

// (b) max_deliver: 2: get() throws the exception twice, then NATS stops redelivering and publishes MAX_DELIVERIES.
$stream = exampleStream('serializer_exception_max_deliver');
$client = (new NatsTransportConfigurationBuilder())->build(exampleDsn($stream))->client;
$client->connect()->await();
$advisories = $client->subscribeQueue('$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES.' . $stream . '.client')->await();
$client->flush()->await();

$transport = new NatsTransport(exampleDsn($stream, 'ack_wait=1&max_deliver=2'), ['max_batch_timeout' => 0.2], $errorSerializer);
try {
    $transport->setup();
    $transport->send(new Envelope(new ExampleMessage('invoice 12')));

    $thrown = $pullFor($transport, 4.0);

    $advisories->setTimeout(0.5);
    $advisory = $advisories->next();
    $payload = $advisory !== null ? json_decode($advisory->payload, true) : null;
    $counts = consumerCounts($stream);
    $stored = storedMessages($stream);

    if (
        count($thrown) !== 2 || $advisories->next() !== null || !is_array($payload)
        || ($payload['stream_seq'] ?? null) !== 1 || ($payload['deliveries'] ?? null) !== 2
        || $counts['ackPending'] !== 0 || $stored !== 1
    ) {
        throw new RuntimeException(sprintf(
            'expected 2 throws and one MAX_DELIVERIES advisory for stream sequence 1 with 2 deliveries, nothing pending and 1 message stored; got %d throws, advisory %s, consumer %s, stored %d',
            count($thrown),
            json_encode($payload),
            json_encode($counts),
            $stored,
        ));
    }

    printf(
        "OK serializer-exception: with max_deliver 2, get() threw the serializer's exception twice and then returned nothing; NATS published MAX_DELIVERIES for stream sequence %d with %d deliveries, nothing is pending, and the stream keeps its 1 message\n",
        $payload['stream_seq'],
        $payload['deliveries'],
    );
} finally {
    $transport->close();
    $client->disconnect()->await();
    deleteExampleStream($stream);
}
