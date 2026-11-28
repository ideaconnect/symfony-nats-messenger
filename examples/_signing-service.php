<?php

/**
 * The other process of the example external-signer.php: it holds an NKey seed and signs the nonces it is sent, so that the
 * process with the transport never has the seed, as a sidecar, a KMS, an HSM or Vault's transit engine keeps it. Not an
 * example itself (the runner skips files starting with "_"). The functional suite runs it as well.
 *
 * It reads the seed from its standard input, listens on a loopback TCP port the system picks, and prints "listening on
 * PORT" once it does. Each connection sends one nonce on a line and gets its signature back on a line, in base64url, as
 * the client puts it in the CONNECT. It stops after LIFETIME seconds, so that one a killed run leaves behind goes away.
 *
 * Run: php examples/_signing-service.php < a-file-with-the-seed
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use IDCT\NATS\Auth\NkeySeedSigner;

const LIFETIME = 300;

$signer = new NkeySeedSigner(trim((string) stream_get_contents(STDIN)));

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
if ($server === false) {
    fwrite(STDERR, sprintf("The signing service cannot listen: %s\n", $error));
    exit(1);
}

$address = (string) stream_socket_get_name($server, false);
echo 'listening on ', substr($address, strrpos($address, ':') + 1), "\n";

$deadline = time() + LIFETIME;
while (time() < $deadline) {
    $connection = @stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }

    stream_set_timeout($connection, 2);
    $nonce = fgets($connection);
    if ($nonce !== false) {
        fwrite($connection, $signer->sign(rtrim($nonce, "\r\n")) . "\n");
    }
    fclose($connection);
}
