<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

/**
 * Builds NKeys for the unit tests when they run, so that no private key has to sit in the repository, where security
 * scanners report it, test ones included (#9). A name always gives the same key, so a failure can be reproduced.
 *
 * No server trusts these keys. The real logins of the functional suite and the examples use the keys
 * tests/nats/auth/generate.sh makes.
 */
final class TestNkeys
{
    public const USER = 20 << 3;
    public const ACCOUNT = 0;
    public const OPERATOR = 14 << 3;
    public const SERVER = 13 << 3;
    public const CLUSTER = 2 << 3;
    public const CURVE = 23 << 3;

    private const PREFIX_SEED = 18 << 3;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * The encoded seed of the key with this name: "SU..." for a user, "SA..." for an account, and so on.
     */
    public static function seed(string $name = 'test-user', int $type = self::USER): string
    {
        $prefix = chr(self::PREFIX_SEED | ($type >> 5)) . chr(($type & 31) << 3);

        return self::encode($prefix . self::rawSeed($name));
    }

    /**
     * That seed with its last character changed, which no longer decodes: the client's checksum refuses it.
     */
    public static function mistypedSeed(string $name = 'test-user'): string
    {
        $seed = self::seed($name);

        return substr($seed, 0, -1) . ($seed[-1] === 'B' ? 'C' : 'B');
    }

    /**
     * The encoded public key of the user with this name: "U...". It is worked out here rather than with the client's
     * signer, so that a test comparing the two checks the client.
     */
    public static function publicKey(string $name = 'test-user', int $type = self::USER): string
    {
        $keyPair = sodium_crypto_sign_seed_keypair(self::rawSeed($name));

        return self::encode(chr($type) . sodium_crypto_sign_publickey($keyPair));
    }

    /**
     * The 32 bytes of the public key with this name, which check a signature.
     */
    public static function rawPublicKey(string $name = 'test-user'): string
    {
        return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(self::rawSeed($name)));
    }

    private static function rawSeed(string $name): string
    {
        return hash('sha256', 'symfony-nats-messenger test key ' . $name, true);
    }

    /**
     * Adds the CRC-16 and encodes in base32 with no padding, as NKeys are.
     */
    private static function encode(string $bytes): string
    {
        $crc = 0;
        foreach (str_split($bytes) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (str_split($bytes . pack('v', $crc)) as $byte) {
            $buffer = (($buffer << 8) | ord($byte)) & 0xFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
            }
        }

        return $bits > 0 ? $encoded . self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31] : $encoded;
    }
}
