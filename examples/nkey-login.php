<?php

/**
 * NKey login - a user the server knows only by its public key.
 *
 * The transport gets a seed and no user, password or token. It signs the nonce the server sends with that seed, and
 * the server checks the signature against the public key in its list of users (tests/nats/nats.conf). A seed the
 * server does not know is refused, which shows that the login was not let through for some other reason.
 *
 * The transport refuses what cannot work when it is built, with no connection: the public key alone, which signs
 * nothing, and a seed next to a password, which a server would ignore without a word.
 *
 * Mirrors the README "NKey Authentication" section. Needs the test server (composer nats:start, which also generates
 * the keys) and the sodium extension. Run: php examples/nkey-login.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

const NKEY_SERVER_PORT = 4222;

if (getenv('NATS_DSN') !== false && getenv('NATS_DSN') !== '') {
    echo "SKIP nkey-login: the NKey user exists only on the test server nats_test, and NATS_DSN points the examples at another server\n";
    exit(0);
}
requireLoginServer('nkey-login', NKEY_SERVER_PORT);

$credentials = loginCredentials('nkey-login');
$user = $credentials['nkey_user'];

assertLoginWorks(NKEY_SERVER_PORT, exampleStream('nkey_login'), ['nkey_seed' => $user['seed']]);

// A valid seed, of a user this server does not list.
assertLoginIsRefused(NKEY_SERVER_PORT, ['nkey_seed' => $credentials['unknown_user']['seed']]);

assertRefusedWhenBuilt(NKEY_SERVER_PORT, ['nkey' => $user['public']], 'The nkey option needs nkey_seed');
assertRefusedWhenBuilt(
    NKEY_SERVER_PORT,
    ['nkey_seed' => $user['seed'], 'username' => 'admin', 'password' => 'password'],
    'The nkey_seed option cannot be combined with',
);

echo "OK nkey-login: the seed alone set up a stream, sent and received a message; a seed the server does not know got Authorization Violation; the public key alone and a seed next to a password were refused when the transport was built\n";
