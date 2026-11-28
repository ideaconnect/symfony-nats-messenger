<?php

/**
 * External signer - an NKey login whose seed stays in another process.
 *
 * The transport gets the user's public key in nkey and, in nonce_signer, the name of a service that signs the nonce the
 * server sends. Here that service asks a second process for each signature (_signing-service.php, which holds the seed),
 * as a signer asks a sidecar, a KMS, an HSM or Vault's transit engine. The transport's process needs neither the seed nor
 * the sodium extension; this example reads the test seed only to start the second process with it. The server checks the
 * signature against the public key, so a signing process that holds another user's seed is refused.
 *
 * In an application the signer is a class of its own that implements NonceSignerInterface, and the bundle hands the
 * transport factory every such service it autoconfigures. Outside the framework, as here, the transport takes them as its
 * fifth argument: a PSR-11 container, keyed by the name nonce_signer holds.
 *
 * The transport refuses what cannot work when it is built, with no connection: a signer with neither nkey nor jwt, which
 * the client would never ask, and a signer next to a seed.
 *
 * Mirrors the README "External Signer" section. Needs the test server (composer nats:start, which also generates the
 * keys). Run: php examples/external-signer.php
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use IDCT\NATS\Auth\NonceSignerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

const NKEY_SERVER_PORT = 4222;

/**
 * Asks the signing process for the signature of a nonce, over loopback TCP. It has to answer within the server's
 * authorization timeout, 2 seconds by default, and while it waits the whole process waits with it.
 */
final class SigningServiceClient implements NonceSignerInterface
{
    public function __construct(private readonly string $address)
    {
    }

    public function sign(string $nonce): string
    {
        $connection = @stream_socket_client($this->address, $errorCode, $error, 1.0);
        if ($connection === false) {
            throw new RuntimeException(sprintf('The signing service cannot be reached: %s', $error));
        }

        try {
            stream_set_timeout($connection, 1);
            fwrite($connection, $nonce . "\n");
            $signature = trim((string) fgets($connection));
        } finally {
            fclose($connection);
        }

        if ($signature === '') {
            throw new RuntimeException('The signing service sent no signature.');
        }

        return $signature;
    }
}

/**
 * The nonce signers a transport finds a service in by the name nonce_signer holds, as the bundle's locator does.
 */
function nonceSigners(NonceSignerInterface $signer): ServiceLocator
{
    return new ServiceLocator(['app.nonce_signer' => static fn (): NonceSignerInterface => $signer]);
}

/**
 * Starts a signing process with the seed on its standard input, and returns it with the address it listens on.
 *
 * @return array{resource, string}
 */
function startSigningService(string $seed): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/_signing-service.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('the signing service did not start');
    }

    fwrite($pipes[0], $seed);
    fclose($pipes[0]);
    $line = trim((string) fgets($pipes[1]));
    if (preg_match('/^listening on (\d+)$/', $line, $port) !== 1) {
        proc_terminate($process);

        throw new RuntimeException('the signing service did not start: ' . $line);
    }

    return [$process, 'tcp://127.0.0.1:' . $port[1]];
}

if (getenv('NATS_DSN') !== false && getenv('NATS_DSN') !== '') {
    echo "SKIP external-signer: the NKey user exists only on the test server nats_test, and NATS_DSN points the examples at another server\n";
    exit(0);
}
requireLoginServer('external-signer', NKEY_SERVER_PORT);

$credentials = loginCredentials('external-signer');
$user = $credentials['nkey_user'];
$options = ['nonce_signer' => 'app.nonce_signer', 'nkey' => $user['public']];

[$signingService, $address] = startSigningService($user['seed']);
[$anotherUsersService, $anotherUsersAddress] = startSigningService($credentials['unknown_user']['seed']);

try {
    $signer = new SigningServiceClient($address);
    assertLoginWorks(NKEY_SERVER_PORT, exampleStream('external_signer'), $options, nonceSigners($signer));

    // The signature of another user's seed does not verify against the public key in nkey.
    assertLoginIsRefused(NKEY_SERVER_PORT, $options, nonceSigners(new SigningServiceClient($anotherUsersAddress)));

    assertRefusedWhenBuilt(NKEY_SERVER_PORT, ['nonce_signer' => 'app.nonce_signer'], 'The nonce_signer option needs nkey or jwt', nonceSigners($signer));
    assertRefusedWhenBuilt(
        NKEY_SERVER_PORT,
        $options + ['nkey_seed' => $user['seed']],
        'The nonce_signer option cannot be combined with',
        nonceSigners($signer),
    );
} finally {
    proc_terminate($signingService);
    proc_terminate($anotherUsersService);
}

echo "OK external-signer: a signer in another process set up a stream, sent and received a message with the public key alone in this one; a signing process with another user's seed got Authorization Violation; a signer with no nkey or jwt and a signer next to a seed were refused when the transport was built\n";
