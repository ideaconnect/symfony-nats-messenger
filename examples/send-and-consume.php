<?php

/**
 * Send and consume - the transport's basic round trip.
 *
 * Provisions the stream and its durable pull consumer with setup() (what messenger:setup-transports runs),
 * sends three messages, reads the queue depth (what messenger:stats shows), then receives and acknowledges
 * them the way a worker does, until the queue is empty.
 *
 * Mirrors the README "Quick Start". Needs the test server (composer nats:start).
 * Run: php examples/send-and-consume.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$stream = exampleStream('send_consume');
// PhpSerializer keeps the example free of extensions; the README recommends IgbinarySerializer.
$transport = new NatsTransport(exampleDsn($stream), [], new PhpSerializer());

try {
    $transport->setup();

    foreach (['first', 'second', 'third'] as $text) {
        $transport->send(new Envelope(new ExampleMessage($text)));
    }
    $waiting = $transport->getMessageCount();

    $received = [];
    // get() returns up to `batching` messages (1 by default), or none once the queue is empty.
    while (($envelopes = iterator_to_array($transport->get(), false)) !== []) {
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            assert($message instanceof ExampleMessage);
            $received[] = $message->text;
            // Acknowledged only after it was handled: until then NATS would redeliver it.
            $transport->ack($envelope);
        }
    }

    if ($waiting !== 3 || $received !== ['first', 'second', 'third'] || $transport->getMessageCount() !== 0) {
        throw new RuntimeException(sprintf('expected 3 waiting and received in order, got %d and [%s]', $waiting, implode(', ', $received)));
    }

    printf("OK send-and-consume: 3 waiting, received %s in order, queue empty after the acks\n", implode(', ', $received));
} finally {
    $transport->close();
    deleteExampleStream($stream);
}
