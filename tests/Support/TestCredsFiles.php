<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * Writes `.creds` files for the unit tests, in the form `nsc generate creds` writes them, with a seed from
 * {@see TestNkeys} and a JWT-shaped string whose claims the test chooses. No server checks these JWTs: the scripted
 * server records the CONNECT and checks nothing, so its signature part is a placeholder. The real files of the
 * functional suite and the examples come from tests/nats/auth/generate.sh.
 *
 * Each instance owns a directory under the system's temporary one, which {@see remove()} deletes with its files.
 */
final class TestCredsFiles
{
    private readonly string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/nats-messenger-creds-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create ' . $this->directory);
        }
    }

    /**
     * A JWT-shaped string: a header, the claims and a placeholder signature, each base64url-encoded. With $expiresAt the
     * claims carry `exp`, as a JWT of `nsc add user --expiry` does.
     */
    public static function jwt(string $user = 'test-user', ?int $expiresAt = null): string
    {
        $claims = ['jti' => 'test-' . $user, 'iat' => 1_700_000_000, 'sub' => TestNkeys::publicKey($user), 'name' => $user];
        if ($expiresAt !== null) {
            $claims['exp'] = $expiresAt;
        }

        return self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'ed25519-nkey']))
            . '.' . self::base64Url((string) json_encode($claims))
            . '.' . self::base64Url('not-a-signature-' . $user);
    }

    /**
     * The text of a .creds file with this JWT and seed, laid out as nsc writes one: five dashes on a BEGIN line, six on
     * an END line, and the warning around the seed.
     */
    public static function text(string $jwt, string $seed): string
    {
        return "-----BEGIN NATS USER JWT-----\n" . $jwt . "\n------END NATS USER JWT------\n\n"
            . "************************* IMPORTANT *************************\n"
            . "NKEY Seed printed below can be used to sign and prove identity.\n"
            . "NKEYs are sensitive and should be treated as secrets.\n\n"
            . "-----BEGIN USER NKEY SEED-----\n" . $seed . "\n------END USER NKEY SEED------\n\n"
            . "*************************************************************\n";
    }

    /**
     * Writes the file of $user (its JWT from {@see jwt()}, its seed from {@see TestNkeys::seed()}) under $name, and
     * returns its path.
     */
    public function write(string $name = 'user.creds', string $user = 'test-user', ?int $expiresAt = null): string
    {
        return $this->writeText($name, self::text(self::jwt($user, $expiresAt), TestNkeys::seed($user)));
    }

    /**
     * Writes $text under $name, as a rotation does: into a new file first, which is then renamed over the old one, so
     * that a reader never meets half a file. Returns the path.
     */
    public function writeText(string $name, string $text): string
    {
        $path = $this->path($name);
        $next = $path . '.next';
        file_put_contents($next, $text);
        rename($next, $path);

        return $path;
    }

    /** The path a file named $name has in the directory, whether or not it exists. */
    public function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    /** The directory itself, which is no file. */
    public function directory(): string
    {
        return $this->directory;
    }

    /** Deletes the directory and its files. */
    public function remove(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            chmod($file, 0600);
            unlink($file);
        }

        rmdir($this->directory);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
