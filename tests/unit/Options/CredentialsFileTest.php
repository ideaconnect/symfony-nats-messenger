<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Unit\Options;

use IDCT\NATS\Exception\NatsException;
use IDCT\NatsMessenger\Options\CredentialsFile;
use IDCT\NatsMessenger\Tests\Support\TestCredsFiles;
use IDCT\NatsMessenger\Tests\Support\TestNkeys;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The .creds file of the creds_file option as the client uses it on every connect: the JWT from {@see CredentialsFile::jwt()},
 * its jwtProvider, then the signature of the server's nonce from {@see CredentialsFile::sign()}, its nonce signer.
 */
#[RequiresPhpExtension('sodium')]
final class CredentialsFileTest extends TestCase
{
    private const NONCE = 'a-nonce-from-the-server';

    private TestCredsFiles $files;

    /** Set while a test runs with exception arguments on; restored after it. */
    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->files = new TestCredsFiles();
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
        $this->files->remove();
    }

    /**
     * One read per connect: the client calls the provider and then sign() for the same CONNECT, and the file may be
     * replaced in between. The signature is made with the seed of the read that gave the JWT, so the CONNECT carries a
     * JWT and a signature of one user. A sign() that read the file again would sign the first user's JWT with the second
     * user's seed, which the server refuses.
     */
    public function testTheSignatureIsMadeWithTheSeedOfTheReadThatGaveTheJwt(): void
    {
        $path = $this->files->write('user.creds', 'test-user');
        $file = CredentialsFile::checkedAt($path);

        $jwt = $file->jwt();
        $this->files->write('user.creds', 'other-user');
        $signature = $file->sign(self::NONCE);

        self::assertSame(TestCredsFiles::jwt('test-user'), $jwt);
        self::assertTrue(self::verifies($signature, 'test-user'), 'the signature verifies against the seed of the read that gave the JWT');
        self::assertFalse(self::verifies($signature, 'other-user'));
    }

    /**
     * The next connect reads the file again, so a replaced file gives the next CONNECT the new JWT and a signature made
     * with the new seed.
     */
    public function testTheNextConnectReadsTheReplacedFile(): void
    {
        $path = $this->files->write('user.creds', 'test-user');
        $file = CredentialsFile::checkedAt($path);
        $file->jwt();
        $file->sign(self::NONCE);

        $this->files->write('user.creds', 'other-user');

        self::assertSame(TestCredsFiles::jwt('other-user'), $file->jwt());
        self::assertTrue(self::verifies($file->sign(self::NONCE), 'other-user'));
    }

    /**
     * The seed waits only for the sign() of the same CONNECT. After it, and after a build, the object holds no seed.
     */
    public function testNoSeedIsKeptAfterTheSignatureOrTheCheck(): void
    {
        $file = CredentialsFile::checkedAt($this->files->write());
        self::assertStringNotContainsString(TestNkeys::seed(), print_r($file, true), 'after the check of the build');

        $file->jwt();
        $file->sign(self::NONCE);

        self::assertStringNotContainsString(TestNkeys::seed(), print_r($file, true), 'after the signature');
    }

    /**
     * A sign() with no read before it, as for a CONNECT that carries no JWT, reads the file itself.
     */
    public function testASignatureWithoutAReadBeforeItReadsTheFile(): void
    {
        $file = CredentialsFile::checkedAt($this->files->write());

        self::assertTrue(self::verifies($file->sign(self::NONCE), 'test-user'));
    }

    /**
     * A file deleted after the build fails the connect that reads it: nothing of an earlier read is kept to fall back
     * on. The error names the option and the path.
     */
    public function testAFileDeletedAfterTheBuildFailsTheConnectNamingTheOptionAndThePath(): void
    {
        $path = $this->files->write();
        $file = CredentialsFile::checkedAt($path);
        unlink($path);

        $failure = self::failureOf(static fn () => $file->jwt());

        self::assertInstanceOf(NatsException::class, $failure);
        self::assertSame('The creds_file option names no file: ' . $path . ' does not exist or is a directory.', $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    /**
     * A file replaced by one the parser cannot read, here a seed block whose END line was cut, fails the connect. The
     * parser's exception holds the whole text in its frames, so it is not chained, and nothing of the text is printed.
     */
    public function testAFileThatIsNoLongerACredsFileFailsTheConnectWithoutItsText(): void
    {
        $path = $this->files->write();
        $file = CredentialsFile::checkedAt($path);
        $this->files->writeText('user.creds', self::withoutTheSeedsEndLine(TestCredsFiles::text(TestCredsFiles::jwt(), TestNkeys::seed())));

        $failure = self::failureOf(static fn () => $file->jwt());

        self::assertSame(
            'The creds_file option names a file that is not a .creds file (Credentials file does not contain a USER NKEY SEED '
            . 'block): ' . $path . '.',
            $failure->getMessage(),
        );
        self::assertNull($failure->getPrevious());
        self::assertStringNotContainsString(substr(TestNkeys::seed(), 0, 15), $failure . print_r($failure->getTrace(), true));
    }

    /**
     * A seed replaced by a mistyped one fails the signature without chaining the client's exception, whose frames hold
     * the seed.
     */
    public function testAMistypedSeedFailsTheSignatureWithoutTheSeed(): void
    {
        $path = $this->files->write();
        $file = CredentialsFile::checkedAt($path);
        $this->files->writeText('user.creds', TestCredsFiles::text(TestCredsFiles::jwt(), TestNkeys::mistypedSeed()));
        $file->jwt();

        $failure = self::failureOf(static fn () => $file->sign(self::NONCE));

        self::assertSame('The creds_file option names a file whose seed cannot be used (Invalid NKey checksum): ' . $path . '.', $failure->getMessage());
        self::assertNull($failure->getPrevious());
        self::assertStringNotContainsString(substr(TestNkeys::mistypedSeed(), 0, 15), $failure . print_r($failure->getTrace(), true));
    }

    public function testAJwtWithAnExpiryLaterThanNowHasNotExpired(): void
    {
        self::assertTrue(CredentialsFile::checkedAt($this->files->write(expiresAt: time() + 3600))->holdsAnUnexpiredJwt());
    }

    public function testAJwtWithoutAnExpiryHasNotExpired(): void
    {
        self::assertTrue(CredentialsFile::checkedAt($this->files->write())->holdsAnUnexpiredJwt());
    }

    public function testAJwtWithAnExpiryInThePastHasExpired(): void
    {
        self::assertFalse(CredentialsFile::checkedAt($this->files->write(expiresAt: time() - 1))->holdsAnUnexpiredJwt());
    }

    /**
     * A JWT has expired once its time has come, so one whose expiry is now has expired: the server ends the connection
     * at that second.
     */
    public function testAJwtWhoseExpiryIsNowHasExpired(): void
    {
        self::assertFalse(CredentialsFile::checkedAt($this->files->write(expiresAt: time()))->holdsAnUnexpiredJwt());
    }

    /**
     * What the file holds now counts, not what it held at the build: the rotation case, and a file nobody renewed.
     */
    public function testTheExpiryIsReadFromTheFileAsItIsNow(): void
    {
        $file = CredentialsFile::checkedAt($this->files->write(expiresAt: time() - 1));
        $this->files->write(expiresAt: time() + 3600);
        self::assertTrue($file->holdsAnUnexpiredJwt(), 'renewed');

        $this->files->write(expiresAt: time() - 1);
        self::assertFalse($file->holdsAnUnexpiredJwt(), 'replaced by an expired one');
    }

    /**
     * A file that cannot be read, or a JWT whose claims are not JSON, or an expiry that is not a number, cannot be told
     * to hold a JWT that has not expired.
     */
    public function testAFileWhoseExpiryCannotBeReadDoesNotCountAsRenewed(): void
    {
        $path = $this->files->write();
        $file = CredentialsFile::checkedAt($path);

        $this->files->writeText('user.creds', TestCredsFiles::text('not-a-jwt', TestNkeys::seed()));
        self::assertFalse($file->holdsAnUnexpiredJwt(), 'a JWT block without a JWT');

        $this->files->writeText('user.creds', TestCredsFiles::text('aGVhZGVy.bm90IGpzb24.c2ln', TestNkeys::seed()));
        self::assertFalse($file->holdsAnUnexpiredJwt(), 'claims that are not JSON');

        $claims = rtrim(strtr(base64_encode((string) json_encode(['exp' => 'tomorrow'])), '+/', '-_'), '=');
        $this->files->writeText('user.creds', TestCredsFiles::text('aGVhZGVy.' . $claims . '.c2ln', TestNkeys::seed()));
        self::assertFalse($file->holdsAnUnexpiredJwt(), 'an expiry that is not a number');

        unlink($path);
        self::assertFalse($file->holdsAnUnexpiredJwt(), 'no file');
    }

    private static function verifies(string $signature, string $user): bool
    {
        return sodium_crypto_sign_verify_detached(
            sodium_base642bin($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            self::NONCE,
            TestNkeys::rawPublicKey($user),
        );
    }

    private static function withoutTheSeedsEndLine(string $text): string
    {
        return str_replace("------END USER NKEY SEED------\n", '', $text);
    }

    private static function failureOf(\Closure $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $failure) {
            return $failure;
        }

        self::fail('expected the operation to fail');
    }
}
