<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

use IDCT\NATS\Auth\CredentialsParser;
use IDCT\NATS\Auth\NkeySeedSigner;
use IDCT\NATS\Auth\NonceSignerInterface;
use IDCT\NATS\Exception\NatsException;
use InvalidArgumentException;

/**
 * The user's JWT and seed from a `.creds` file, the creds_file option, read on every connect: a file replaced while the
 * process runs gives the next connection its new JWT, which is how a short-lived JWT is rotated.
 *
 * For each CONNECT the client asks for the JWT ({@see jwt()}, its jwtProvider) and then signs the server's nonce
 * ({@see sign()}, its nonce signer). Both come from one read of the file: the JWT goes to the client, and the seed waits
 * for the sign() that follows and is dropped there. A second read for the seed could meet a file replaced in between
 * and sign the first user's JWT with the second user's seed, which the server refuses with a bare Authorization
 * Violation. A file that is missing or cannot be used fails that connection; nothing of an earlier read is kept to fall
 * back on.
 *
 * No error repeats the file's text. Each names the option and the path, and none chains an exception of the client's
 * parser or signer: with zend.exception_ignore_args off their frames hold the text or the seed as an argument.
 */
final class CredentialsFile implements NonceSignerInterface
{
    /** What a JWT looks like: three base64url parts separated by dots, as the jwt option requires. */
    private const JWT_PATTERN = '/^[\w-]+\.[\w-]+\.[\w-]+$/';

    /** The seed of the latest read, kept for the sign() that follows it, and null after that. */
    private ?string $seedOfTheLatestRead = null;

    private function __construct(public readonly string $path)
    {
    }

    /**
     * The file at $path, once it has been read as a connect reads it, and found to hold a JWT and a user's seed. Nothing
     * of what was read is kept: every connect reads the file again.
     *
     * @throws InvalidArgumentException When the file cannot be read, is not a .creds file, holds a value that is not a JWT
     *                                  or a seed that is not a user's, or when the sodium extension is missing.
     */
    public static function checkedAt(string $path): self
    {
        $file = new self($path);

        try {
            $credentials = $file->read();
        } catch (NatsException $unusable) {
            throw new InvalidArgumentException($unusable->getMessage());
        }

        try {
            if (preg_match(self::JWT_PATTERN, $credentials['jwt']) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'The creds_file option names a file whose NATS USER JWT block does not hold a JWT, which has three parts '
                    . 'separated by dots: %s.',
                    $path,
                ));
            }

            if (!str_starts_with(self::signerFor($credentials['nkeySeed'], $path)->publicKey(), 'U')) {
                throw new InvalidArgumentException(sprintf(
                    'The creds_file option names a file whose USER NKEY SEED block does not hold a user seed (SU...): %s.',
                    $path,
                ));
            }
        } catch (NatsException $unusable) {
            throw new InvalidArgumentException($unusable->getMessage());
        } finally {
            self::wipe($credentials['nkeySeed']);
        }

        return $file;
    }

    /**
     * The JWT for the CONNECT the client writes now, read from the file. The seed of the same read waits for the
     * {@see sign()} that follows.
     *
     * @throws NatsException When the file cannot be read or is not a .creds file.
     */
    public function jwt(): string
    {
        $credentials = $this->read();
        self::wipe($this->seedOfTheLatestRead);
        $this->seedOfTheLatestRead = $credentials['nkeySeed'];

        return $credentials['jwt'];
    }

    /**
     * Signs the server's nonce with the seed of the read that gave the JWT, and drops that seed. Without such a read, as
     * for a CONNECT that carries no JWT, the file is read now.
     *
     * @throws NatsException When the seed cannot sign, or the file cannot be read.
     */
    public function sign(string $nonce): string
    {
        $seed = $this->seedOfTheLatestRead ?? $this->read()['nkeySeed'];
        $this->seedOfTheLatestRead = null;

        try {
            $signer = self::signerFor($seed, $this->path);
        } finally {
            self::wipe($seed);
        }

        return $signer->sign($nonce);
    }

    /**
     * Whether the JWT the file holds now has not expired: it has no `exp` claim, or one later than now. False when the
     * file cannot be read, or the JWT's claims cannot be read.
     *
     * The transport asks when the server ended a connection at the expiry of the user's JWT, to tell a file that was
     * renewed from one that was not.
     */
    public function holdsAnUnexpiredJwt(): bool
    {
        try {
            $credentials = $this->read();
        } catch (NatsException) {
            return false;
        }

        self::wipe($credentials['nkeySeed']);
        $claims = self::claimsOf($credentials['jwt']);
        if ($claims === null) {
            return false;
        }

        $expiry = $claims['exp'] ?? null;
        if ($expiry === null) {
            return true;
        }

        return (is_int($expiry) || is_float($expiry)) && $expiry > time();
    }

    /**
     * One read of the file: its JWT and its seed.
     *
     * @return array{jwt: string, nkeySeed: string}
     *
     * @throws NatsException When the file cannot be read or is not a .creds file, naming the option and the path.
     */
    private function read(): array
    {
        $contents = is_file($this->path) && is_readable($this->path) ? @file_get_contents($this->path) : false;
        if ($contents === false) {
            throw new NatsException(sprintf('The creds_file option does not name a file that can be read: %s.', $this->path));
        }

        try {
            return CredentialsParser::parse($contents);
        } catch (NatsException $unparsable) {
            // Its message names the block that is missing, and nothing of the text. The exception is not chained: the
            // parser's frame holds the whole text of the file, the seed included, as its argument.
            $missing = $unparsable->getMessage();
            unset($unparsable);

            throw new NatsException(sprintf('The creds_file option names a file that is not a .creds file (%s): %s.', $missing, $this->path));
        } finally {
            self::wipe($contents);
        }
    }

    /**
     * The client's signer for the seed. Its exceptions are not chained: their frames hold the seed as an argument. An
     * \Error comes from a sodium function the client cannot call, such as a disabled one.
     *
     * @throws NatsException When the seed is not one, or sodium cannot be used, naming the option and the path.
     */
    private static function signerFor(#[\SensitiveParameter] string $seed, string $path): NkeySeedSigner
    {
        try {
            return new NkeySeedSigner($seed);
        } catch (NatsException|\Error $unusable) {
            $reason = $unusable->getMessage();
            unset($unusable);

            throw new NatsException(sprintf('The creds_file option names a file whose seed cannot be used (%s): %s.', $reason, $path));
        }
    }

    /**
     * The claims of a JWT, its middle part, or null when that is not base64url-encoded JSON.
     *
     * @return array<array-key, mixed>|null
     */
    private static function claimsOf(#[\SensitiveParameter] string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);

        return is_array($claims) ? $claims : null;
    }

    /**
     * Overwrites a copy of a secret this class holds, where sodium can, and drops it. Best effort: PHP may hold other
     * copies, and the polyfill's sodium_memzero() always fails.
     *
     * @param-out null $secret
     */
    private static function wipe(#[\SensitiveParameter] ?string &$secret): void
    {
        if ($secret !== null && function_exists('sodium_memzero')) {
            try {
                sodium_memzero($secret);
            } catch (\SodiumException) {
                // Dropped below all the same.
            }
        }

        $secret = null;
    }
}
