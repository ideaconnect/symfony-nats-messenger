<?php

/**
 * Token login - a server whose clients all log in with one token.
 *
 * The transport passes the token in the CONNECT and the server compares it with its own (tests/nats/nats-token.conf).
 * A wrong token is refused, which shows that the login was not let through for some other reason.
 *
 * In an application the token goes in the token option, from an environment variable, as the README's "Configuration
 * Options" section shows. Needs the token test server (composer nats:start). Run: php examples/token-login.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

const TOKEN_SERVER_PORT = 4230;

requireLoginServer('token-login', TOKEN_SERVER_PORT);

assertLoginWorks(TOKEN_SERVER_PORT, exampleStream('token_login'), ['token' => 'test-only-token']);

assertLoginIsRefused(TOKEN_SERVER_PORT, ['token' => 'not-the-token']);

echo "OK token-login: the token set up a stream, sent and received a message; a wrong token got Authorization Violation\n";
