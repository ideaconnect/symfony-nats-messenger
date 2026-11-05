<?php

/**
 * JWT login - a user of a server in operator mode, with its JWT and its seed.
 *
 * In operator mode the server has no list of users. It trusts an operator, and with it the accounts the operator
 * signed (tests/nats/auth/resolver.conf). The transport sends the user's JWT, which an account signed, and signs the
 * nonce the server sends with the user's seed, which is how the server knows the JWT is the client's own. So a JWT
 * with another user's seed is refused, as is a JWT past its expiry.
 *
 * The transport refuses a JWT with no seed when it is built, with no connection, as it could never log in.
 *
 * Both values are in the user's .creds file. In an application they go in the jwt and nkey_seed options, from
 * environment variables. Here they come from the credentials composer nats:start generates, which
 * tests/nats/auth/README.md describes.
 *
 * Mirrors the README "JWT Users (Operator Mode)" section. Needs the operator-mode test server (composer nats:start)
 * and the sodium extension. Run: php examples/jwt-login.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

const OPERATOR_MODE_PORT = 4229;

requireLoginServer('jwt-login', OPERATOR_MODE_PORT);

$credentials = loginCredentials('jwt-login');
$user = $credentials['jwt_user'];

assertLoginWorks(OPERATOR_MODE_PORT, exampleStream('jwt_login'), ['jwt' => $user['jwt'], 'nkey_seed' => $user['seed']]);

assertLoginIsRefused(OPERATOR_MODE_PORT, ['jwt' => $user['jwt'], 'nkey_seed' => $credentials['unknown_user']['seed']]);
assertLoginIsRefused(OPERATOR_MODE_PORT, ['jwt' => $credentials['expired_user']['jwt'], 'nkey_seed' => $credentials['expired_user']['seed']]);

assertRefusedWhenBuilt(OPERATOR_MODE_PORT, ['jwt' => $user['jwt']], 'The jwt option needs nkey_seed');

echo "OK jwt-login: the JWT and its seed set up a stream, sent and received a message; the JWT with another user's seed and an expired JWT got Authorization Violation; the JWT alone was refused when the transport was built\n";
