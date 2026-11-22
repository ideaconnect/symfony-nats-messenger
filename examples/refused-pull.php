<?php

/**
 * Refused pull - a worker whose user may not pull stops with the permission it needs.
 *
 * A pull is a publish to $JS.API.CONSUMER.MSG.NEXT.<stream>.<consumer>. A user who may not publish there gets each
 * pull refused: the server answers with a permissions violation and keeps the connection open. get() then fails with a
 * TransportException that names the stream, the consumer and that subject, the client's PermissionViolationException
 * as its previous one, so messenger:consume exits with that error. Before, get() waited max_batch_timeout plus a second
 * and returned nothing, every time, and the worker looked healthy while the messages waited (#130).
 *
 * The admin user sets the stream and the consumer up and sends a message. The user nopull of tests/nats/nats.conf,
 * who may do everything but pull from a consumer named "worker", then pulls, with auto_setup off, as a worker in
 * production runs. The admin's worker receives the message afterwards: the refused pull took nothing.
 *
 * Mirrors the README "Non-obvious behavior" of get() and its list of the permissions a worker needs. Needs the test
 * server (composer nats:start), which knows the user nopull. Run: php examples/refused-pull.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\Exception\PermissionViolationException;
use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

const REFUSED_PULL_SERVER_PORT = 4222;

if (getenv('NATS_DSN') !== false && getenv('NATS_DSN') !== '') {
    echo "SKIP refused-pull: the user nopull exists only on the test server nats_test, and NATS_DSN points the examples at another server\n";
    exit(0);
}
requireLoginServer('refused-pull', REFUSED_PULL_SERVER_PORT);

$stream = exampleStream('refused_pull');
$query = 'consumer=worker&max_batch_timeout=1';
$admin = new NatsTransport(exampleDsn($stream, $query), [], new PhpSerializer());
$worker = new NatsTransport(
    sprintf('nats-jetstream://nopull:nopull@127.0.0.1:%d/%s/%s.messages?%s&auto_setup=false', REFUSED_PULL_SERVER_PORT, $stream, $stream, $query),
    [],
    new PhpSerializer(),
);
$subject = '$JS.API.CONSUMER.MSG.NEXT.' . $stream . '.worker';

try {
    $admin->setup();
    $admin->send(new Envelope(new ExampleMessage('waiting')));

    $start = hrtime(true);
    try {
        $envelopes = iterator_to_array($worker->get(), false);

        throw new RuntimeException(sprintf(
            'expected get() of the user nopull to fail, it returned %d message(s) after %.3f s',
            count($envelopes),
            (hrtime(true) - $start) / 1e9,
        ));
    } catch (TransportException $refused) {
        $seconds = (hrtime(true) - $start) / 1e9;
    }

    $expected = sprintf('Cannot pull from NATS stream "%s", consumer "worker". Grant the worker permission to publish to "%s".', $stream, $subject);
    $cause = $refused->getPrevious();
    if ($refused->getMessage() !== $expected || !$cause instanceof PermissionViolationException || $cause->getSubject() !== $subject) {
        throw new RuntimeException(sprintf('get() failed with "%s", caused by %s', $refused->getMessage(), $cause === null ? 'nothing' : $cause::class));
    }

    $received = [];
    while (($envelopes = iterator_to_array($admin->get(), false)) !== []) {
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            assert($message instanceof ExampleMessage);
            $received[] = $message->text;
            $admin->ack($envelope);
        }
    }
    if ($received !== ['waiting']) {
        throw new RuntimeException(sprintf('expected the admin to receive the waiting message, got [%s]', implode(', ', $received)));
    }

    printf(
        "OK refused-pull: get() of the user nopull failed after %.3f s (max_batch_timeout 1 s) with \"%s\"; the admin then received the waiting message\n",
        $seconds,
        $refused->getMessage(),
    );
} finally {
    $worker->close();
    $admin->close();
    deleteExampleStream($stream);
}
