<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use IDCT\NATS\Auth\NkeySeedSigner;
use IDCT\NATS\Auth\NonceSignerInterface;

/**
 * A signer of the server's nonce that is not the seed's, as an application's own service is one (a signer that asks a
 * KMS, an HSM, Vault or a sidecar). It signs with a test user's seed, so that a signature it makes verifies against that
 * user's public key, and it records each nonce it was asked to sign.
 *
 * The seed is made only when sign() is called, so a test that never connects needs no sodium.
 */
final class RecordingNonceSigner implements NonceSignerInterface
{
    /** @var list<string> */
    public array $nonces = [];

    public function __construct(private readonly string $user = 'test-user')
    {
    }

    public function sign(string $nonce): string
    {
        $this->nonces[] = $nonce;

        return (new NkeySeedSigner(TestNkeys::seed($this->user)))->sign($nonce);
    }
}
