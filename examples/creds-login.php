<?php

/**
 * .creds login - a user of a server in operator mode that logs in from its .creds file, the creds_file option.
 *
 * `nsc generate creds` writes a user's JWT and seed into one file. The transport reads that file on every connect, so a
 * file replaced while the process runs gives the next connection the JWT it holds now: that is how a short-lived JWT is
 * rotated, by writing the new file and renaming it over the old one. Here one transport logs in as one user, then, once
 * the file was replaced by another user's and the transport closed, as that user, and then, with an expired user's file
 * in its place, is refused by the server, which shows the file is read again for each connection.
 *
 * The transport refuses creds_file next to another credential, and a path that is not a file it can read, when it is
 * built, with no connection.
 *
 * The files are the ones composer nats:start generates (tests/nats/auth/README.md); the example works on a copy.
 * Mirrors the README "JWT Users (Operator Mode)" section. Needs the operator-mode test server (composer nats:start) and
 * the sodium extension. Run: php examples/creds-login.php
 */

declare(strict_types=1);

use IDCT\NatsMessenger\NatsTransport;
use IDCT\NatsMessenger\Options\NatsTransportConfigurationBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

require __DIR__ . '/_bootstrap.php';

const OPERATOR_MODE_PORT = 4229;

requireLoginServer('creds-login', OPERATOR_MODE_PORT);

$credentials = loginCredentials('creds-login');

/** The .creds file of $user that composer nats:start generated. */
function generatedCredsFile(string $user): string
{
    $file = __DIR__ . '/../tests/nats/auth/' . $user . '.creds';
    if (!is_file($file)) {
        printf("SKIP creds-login: needs the test credentials (composer nats:start)\n");
        exit(0);
    }

    return $file;
}

/** Replaces $file with the .creds file of $user as a rotation does: a new file, renamed over the old one. */
function replaceCredsFile(string $file, string $user): void
{
    if (!copy(generatedCredsFile($user), $file . '.next') || !rename($file . '.next', $file)) {
        throw new RuntimeException('cannot replace ' . $file);
    }
}

$file = (string) tempnam(sys_get_temp_dir(), 'creds-login-');
replaceCredsFile($file, 'test-user');
$stream = exampleStream('creds_login');
$transport = new NatsTransport(loginDsn(OPERATOR_MODE_PORT, $stream), ['creds_file' => $file], new PhpSerializer());

try {
    // Each round runs on a connection of its own: close() ends the one before, and the next operation connects again.
    $transport->setup();
    foreach (['test-user', 'bearer-user'] as $user) {
        replaceCredsFile($file, $user);
        $transport->send(new Envelope(new ExampleMessage('from ' . $user)));
        $envelopes = iterator_to_array($transport->get(), false);
        foreach ($envelopes as $envelope) {
            $transport->ack($envelope);
        }

        $message = ($envelopes[0] ?? null)?->getMessage();
        if (count($envelopes) !== 1 || !$message instanceof ExampleMessage || $message->text !== 'from ' . $user) {
            throw new RuntimeException(sprintf('expected the message of %s, got %d messages', $user, count($envelopes)));
        }
        $transport->close();
    }

    replaceCredsFile($file, 'expired-user');
    try {
        $transport->send(new Envelope(new ExampleMessage('from an expired user')));
        throw new RuntimeException('the server accepted the expired user\'s file');
    } catch (RuntimeException $refused) {
        if (!str_contains($refused->getMessage(), 'Authorization Violation')) {
            throw $refused;
        }
    }
} finally {
    $transport->close();
    replaceCredsFile($file, 'test-user');
    $client = (new NatsTransportConfigurationBuilder())->build(loginDsn(OPERATOR_MODE_PORT, $stream), ['creds_file' => $file])->client;
    $client->connect()->await();
    $client->jetStream()->deleteStream($stream)->await();
    $client->disconnect()->await();
    unlink($file);
}

assertRefusedWhenBuilt(OPERATOR_MODE_PORT, ['creds_file' => generatedCredsFile('test-user'), 'jwt' => $credentials['jwt_user']['jwt']], 'The creds_file option cannot be combined with jwt');
assertRefusedWhenBuilt(OPERATOR_MODE_PORT, ['creds_file' => sys_get_temp_dir() . '/no-such-file.creds'], 'The creds_file option names no file');

echo "OK creds-login: one transport sent and received as the user of its .creds file, then as the user whose file replaced it, and was refused with Authorization Violation once an expired user's file was in its place; creds_file next to a jwt and a missing file were refused when the transport was built\n";
