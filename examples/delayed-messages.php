<?php

/**
 * Delayed messages - with `scheduled_messages: true`, a message sent with a DelayStamp is held by the NATS server
 * and delivered when its time has come. That needs NATS 2.12 or later, and on an older server the transport
 * refuses to schedule instead of losing the message (5.9.0).
 *
 * A server before 2.12 has no message schedules, and says nothing about it: it accepts the stream's setting and
 * ignores it, and a message published with a schedule is stored on its delayed subject and never delivered. So
 * the transport reads the version the server gave when the connection opened. Up to 5.8.1 the setup succeeded on
 * such a server, and every delayed message was lost that way, Symfony's retry copies among them.
 *
 * The example reads the server's version and shows what applies to it:
 *
 * - On NATS 2.12 and later, a message sent with a delay of 1.5 s arrives 1.5 to 2.5 s later: the server delivers
 *   at whole seconds, and the transport rounds up, so that a message is never early.
 * - On NATS 2.12 and later, a stream with interest retention stores nothing published to a subject no consumer has
 *   interest in, the delayed subjects included, so setup() refuses `scheduled_messages` there (5.9.1), before it
 *   creates the stream. With the stream made by hand and a consumer on the delayed subjects that nobody pulls from,
 *   setup() goes on and the delayed message arrives. Up to 5.9.0 the setup passed, and every delayed message on such
 *   a stream was lost, Symfony's retry copies among them.
 * - On an older server, setup() with `scheduled_messages` is refused and creates no stream. On a stream that was
 *   set up without the option, the send of a delayed message is refused too, and stores nothing.
 *
 * On both, a message without a delay is delivered at once.
 *
 * For Symfony's retry delays on a server without schedules, use `retry_via: nak` (examples/retry-via-nak.php).
 *
 * Mirrors the README "Delayed / Scheduled Messages" section. Needs the test server (composer nats:start).
 * Run: php examples/delayed-messages.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\Exception\JetStreamException;
use IDCT\NATS\JetStream\Configuration\ConsumerConfiguration;
use IDCT\NATS\JetStream\Configuration\StreamConfiguration;
use IDCT\NATS\JetStream\Enum\AckPolicy;
use IDCT\NATS\JetStream\Enum\RetentionPolicy;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$transportOn = static fn (string $stream, string $query): NatsTransport => new NatsTransport(exampleDsn($stream, $query), ['max_batch_timeout' => 0.2], new PhpSerializer());

/**
 * Receives through the transport until $count messages arrived, or $seconds passed, acknowledging each.
 *
 * @return list<array{text: string, after: float}> each message, with the seconds since $since
 */
$receive = static function (NatsTransport $transport, int $count, float $since, float $seconds): array {
    $arrived = [];
    $deadline = microtime(true) + $seconds;
    while (count($arrived) < $count && microtime(true) < $deadline) {
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            $arrived[] = ['text' => $message instanceof ExampleMessage ? $message->text : '?', 'after' => microtime(true) - $since];
            $transport->ack($envelope);
        }
    }

    return $arrived;
};

$version = serverVersion();
$stream = exampleStream('delayed');

if (serverHasMessageSchedules()) {
    $transport = $transportOn($stream, 'scheduled_messages=true');
    try {
        $transport->setup();

        $sentAt = microtime(true);
        $transport->send(new Envelope(new ExampleMessage('in a moment'), [new DelayStamp(1500)]));
        $transport->send(new Envelope(new ExampleMessage('now')));
        $arrived = $receive($transport, 2, $sentAt, 8.0);

        $texts = array_map(static fn (array $message): string => $message['text'], $arrived);
        if ($texts !== ['now', 'in a moment'] || $arrived[0]['after'] >= 1.0 || $arrived[1]['after'] < 1.45 || $arrived[1]['after'] >= 5.0) {
            throw new RuntimeException(sprintf('expected the message without a delay at once and the delayed one 1.5 to 2.5 s after it was sent; got %s', json_encode($arrived)));
        }

        printf(
            "OK delayed-messages: on NATS %s, which has message schedules, a message sent with a delay of 1.5 s arrived %.2f s later, and one without a delay %.2f s later\n",
            $version,
            $arrived[1]['after'],
            $arrived[0]['after'],
        );
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }

    // A stream with interest retention: refused until a consumer has interest in the delayed subjects.
    $stream = exampleStream('delayed_interest');
    $query = 'scheduled_messages=true&stream_retention=interest';
    $transport = $transportOn($stream, $query);
    try {
        $refusal = '';
        try {
            $transport->setup();
        } catch (RuntimeException $refused) {
            $refusal = $refused->getMessage();
        }
        if (!str_contains($refusal, 'the stream has interest retention and no consumer has interest in')) {
            throw new RuntimeException('expected setup() to refuse scheduled_messages on an interest stream nothing has interest in, it said: ' . $refusal);
        }
        // Refused before it was created: NATS never turns a stream's schedules off once a write turned them on.
        $created = withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): bool {
            try {
                $jetStream->getStream($stream)->await();

                return true;
            } catch (JetStreamException) {
                return false;
            }
        });
        if ($created) {
            throw new RuntimeException('expected the refused setup() to leave no stream behind');
        }

        // The stream made by hand, with the delayed subjects, and the pinning consumer of NATS ADR-51: durable, on the
        // delayed subjects, and nobody pulls from it. setup() then turns the stream's schedules on.
        withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): void {
            $jetStream->addStream(StreamConfiguration::create($stream)
                ->subjects($stream . '.messages', $stream . '.messages.delayed.>')
                ->retention(RetentionPolicy::Interest))->await();
            $jetStream->addConsumer($stream, ConsumerConfiguration::create()
                ->durable('delayed_pin')
                ->filterSubject($stream . '.messages.delayed.>')
                ->ackPolicy(AckPolicy::Explicit))->await();
        });
        $transport = $transportOn($stream, $query);
        $transport->setup();

        $sentAt = microtime(true);
        $transport->send(new Envelope(new ExampleMessage('in a moment, on an interest stream'), [new DelayStamp(1500)]));
        $arrived = $receive($transport, 1, $sentAt, 8.0);
        if (count($arrived) !== 1 || $arrived[0]['after'] < 1.45 || $arrived[0]['after'] >= 5.0) {
            throw new RuntimeException('expected the delayed message on the interest stream 1.5 to 2.5 s after it was sent; got ' . json_encode($arrived));
        }

        printf(
            "OK delayed-messages: on a stream with interest retention, setup() refused scheduled_messages while no consumer had interest in the delayed subjects; with a consumer on them that nobody pulls from it went on, and a message sent with a delay of 1.5 s arrived %.2f s later. The refusal:\n    %s\n",
            $arrived[0]['after'],
            $refusal,
        );
    } finally {
        $transport->close();
        deleteExampleStream($stream);
    }

    exit(0);
}

// A server without message schedules: setup() refuses the option, before it creates anything.
$refusal = '';
$scheduling = $transportOn($stream, 'scheduled_messages=true');
try {
    $scheduling->setup();
} catch (RuntimeException $refused) {
    $refusal = $refused->getMessage();
}
$streamExists = withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): bool {
    try {
        $jetStream->getStream($stream)->await();

        return true;
    } catch (Throwable) {
        return false;
    }
});
if ($streamExists) {
    deleteExampleStream($stream);
}
if (!str_contains($refusal, "The 'scheduled_messages' option requires NATS Server >= 2.12") || $streamExists) {
    throw new RuntimeException(sprintf('expected setup() to refuse scheduled_messages on NATS %s and to create no stream; it said "%s", and the stream %s', $version, $refusal, $streamExists ? 'exists' : 'does not exist'));
}

// A stream set up without the option, as another transport of the application may have left it: the send of a
// delayed message is refused, and nothing is stored that the server would never deliver.
$plain = $transportOn($stream, '');
try {
    $plain->setup();

    $sendRefusal = '';
    try {
        $scheduling->send(new Envelope(new ExampleMessage('in a moment'), [new DelayStamp(1500)]));
    } catch (TransportException $refused) {
        $sendRefusal = $refused->getMessage();
    }
    $storedAfterTheDelayedSend = storedMessages($stream);

    $sentAt = microtime(true);
    $scheduling->send(new Envelope(new ExampleMessage('now')));
    $arrived = $receive($plain, 1, $sentAt, 5.0);

    if (!str_contains($sendRefusal, 'The delayed message was not sent') || $storedAfterTheDelayedSend !== 0 || count($arrived) !== 1 || $arrived[0]['text'] !== 'now') {
        throw new RuntimeException(sprintf('expected the delayed send to be refused and to store nothing, and the send without a delay to arrive; it said "%s", %d stored, arrived: %s', $sendRefusal, $storedAfterTheDelayedSend, json_encode($arrived)));
    }

    printf(
        "OK delayed-messages: on NATS %s, which has no message schedules, setup() refused scheduled_messages and created no stream, the send of a delayed message was refused and stored nothing, and a message without a delay arrived %.2f s later. The refusal:\n    %s\n",
        $version,
        $arrived[0]['after'],
        $sendRefusal,
    );
} finally {
    $scheduling->close();
    $plain->close();
    deleteExampleStream($stream);
}
