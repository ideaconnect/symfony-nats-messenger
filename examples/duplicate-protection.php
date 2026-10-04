<?php

/**
 * Duplicate protection - a message sent again is stored once (5.4.0).
 *
 * JetStream drops a message whose Nats-Msg-Id it already stored within the stream's duplicate window, and the
 * transport fills that header from a DeduplicationIdStamp:
 * - an id the application adds covers a message it dispatches again as a new envelope, say after a send()
 *   that timed out;
 * - with `deduplicate: true` every message gets an id on its first send, which travels with it, so the same
 *   envelope sent again is dropped, while Symfony's retry of it (a higher retry count) is a message of its own.
 *
 * Mirrors the README "Duplicate Protection" section. Needs the test server (composer nats:start).
 * Run: php examples/duplicate-protection.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$stream = exampleStream('dedup');
$transport = new NatsTransport(exampleDsn($stream), ['deduplicate' => true], new PhpSerializer());

try {
    $transport->setup();

    // The application's own id: dispatched twice, as two envelopes, the message is stored once.
    $transport->send(new Envelope(new ExampleMessage('order 42 placed'), [new DeduplicationIdStamp('order-42-placed')]));
    $transport->send(new Envelope(new ExampleMessage('order 42 placed'), [new DeduplicationIdStamp('order-42-placed')]));
    $afterOwnId = storedMessages($stream);

    // An id the transport added on the first send: the envelope send() returns carries it, so sending that
    // envelope again is dropped.
    $sent = $transport->send(new Envelope(new ExampleMessage('order 43 placed')));
    $transport->send($sent);
    $afterResend = storedMessages($stream);

    // Symfony's retry of that message carries a retry count, which makes it a message of its own.
    $transport->send($sent->with(new RedeliveryStamp(1)));
    $afterRetry = storedMessages($stream);

    if ([$afterOwnId, $afterResend, $afterRetry] !== [1, 2, 3]) {
        throw new RuntimeException(sprintf('expected 1, 2 and 3 stored messages, got %d, %d and %d', $afterOwnId, $afterResend, $afterRetry));
    }

    printf(
        "OK duplicate-protection: own id sent twice stored 1, re-sent envelope dropped (2), its retry stored (3); transport id %s\n",
        $sent->last(DeduplicationIdStamp::class)?->id ?? '?',
    );
} finally {
    $transport->close();
    deleteExampleStream($stream);
}
