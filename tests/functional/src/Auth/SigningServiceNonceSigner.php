<?php

declare(strict_types=1);

namespace App\Auth;

use IDCT\NATS\Auth\NonceSignerInterface;

/**
 * Signs the server's nonce by asking the scenario's signing service, another process that holds the seed
 * (examples/_signing-service.php), so that the console commands and the workers never have it. It is autoconfigured
 * (config/services.yaml), so the bundle tags it for nonce_signer, which names it by its class.
 *
 * The scenario puts the service's address in NATS_TEST_SIGNING_SERVICE, which the commands it runs inherit.
 */
final class SigningServiceNonceSigner implements NonceSignerInterface
{
    public function sign(string $nonce): string
    {
        $address = getenv('NATS_TEST_SIGNING_SERVICE');
        if (!is_string($address) || $address === '') {
            throw new \RuntimeException('No signing service: NATS_TEST_SIGNING_SERVICE is not set.');
        }

        $connection = @stream_socket_client($address, $errorCode, $error, 1.0);
        if ($connection === false) {
            throw new \RuntimeException(sprintf('The signing service cannot be reached: %s', $error));
        }

        try {
            stream_set_timeout($connection, 1);
            fwrite($connection, $nonce . "\n");
            $signature = trim((string) fgets($connection));
        } finally {
            fclose($connection);
        }

        if ($signature === '') {
            throw new \RuntimeException('The signing service sent no signature.');
        }

        return $signature;
    }
}
