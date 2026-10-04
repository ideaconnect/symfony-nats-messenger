<?php

/**
 * Request timeout - how long a send waits for its acknowledgement (5.3.0), and why a send that timed out is
 * sent again with a deduplication id (5.4.0).
 *
 * A stream created with no_ack stores what it receives but never acknowledges it, which here stands in for an
 * acknowledgement lost to a stalled server or network. With `request_timeout: 1` the send gives up after one
 * second with the client's TimeoutException, although the server stored the message. Sent again with the same
 * DeduplicationIdStamp, as an application unsure of the outcome would, it times out again, but the stream still
 * holds the one message.
 *
 * Mirrors the README "Request Timeout" and "Duplicate Protection" sections. Needs the test server
 * (composer nats:start). Run: php examples/request-timeout.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\Exception\TimeoutException;
use IDCT\NATS\JetStream\JetStreamContext;
use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

$stream = exampleStream('request_timeout');
withJetStream($stream, static function (JetStreamContext $jetStream) use ($stream): void {
    $jetStream->createStream($stream, [$stream . '.messages'], ['no_ack' => true])->await();
});
$transport = new NatsTransport(exampleDsn($stream), ['request_timeout' => 1], new PhpSerializer());

/** Sends the invoice, which has to time out, and returns how long the send waited. */
$sendInvoice = static function () use ($transport): float {
    $start = microtime(true);
    try {
        $transport->send(new Envelope(new ExampleMessage('invoice 7 issued'), [new DeduplicationIdStamp('invoice-7-issued')]));
    } catch (TimeoutException) {
        // The outcome is unknown: the server may have stored the message. Sending it again is safe only
        // because it carries the same deduplication id.
        return microtime(true) - $start;
    }

    throw new RuntimeException('expected the send to time out, since the stream never acknowledges it');
};

try {
    $firstWait = $sendInvoice();
    $afterFirst = storedMessages($stream);
    $secondWait = $sendInvoice();
    $afterSecond = storedMessages($stream);

    if ($afterFirst !== 1 || $afterSecond !== 1 || $firstWait < 0.9 || $firstWait > 3.0) {
        throw new RuntimeException(sprintf('expected 1 stored message after each send and a wait of about 1 s, got %d, %d and %.2f s', $afterFirst, $afterSecond, $firstWait));
    }

    printf("OK request-timeout: the send gave up after %.1f s and %.1f s, the stream stored the invoice once\n", $firstWait, $secondWait);
} finally {
    $transport->close();
    deleteExampleStream($stream);
}
