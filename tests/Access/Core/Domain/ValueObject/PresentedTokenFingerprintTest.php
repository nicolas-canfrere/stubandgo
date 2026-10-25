<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ValueObject\PresentedTokenFingerprint;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresentedTokenFingerprintTest extends TestCase
{
    public function testFingerprintOfAKnownTokenIsItsSha256(): void
    {
        self::assertSame(hash('sha256', 'abc'), PresentedTokenFingerprint::fromPresented('abc')->toString());
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', PresentedTokenFingerprint::fromPresented('abc')->toString());
    }

    public function testTheSameTokenAlwaysGivesTheSameFingerprint(): void
    {
        self::assertTrue(PresentedTokenFingerprint::fromPresented('abc')->equals(PresentedTokenFingerprint::fromPresented('abc')));
        self::assertFalse(PresentedTokenFingerprint::fromPresented('abc')->equals(PresentedTokenFingerprint::fromPresented('abd')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anyPresentedString(): iterable
    {
        yield 'empty' => [''];
        yield '10000 characters' => [str_repeat('a', 10000)];
        yield 'invalid UTF-8' => ["a\xFFb"];
        yield 'NUL byte' => ["a\0b"];
    }

    #[DataProvider('anyPresentedString')]
    public function testAnyStringGives64LowercaseHexCharacters(string $presented): void
    {
        $fingerprint = PresentedTokenFingerprint::fromPresented($presented)->toString();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);
        self::assertSame(hash('sha256', $presented), $fingerprint);
    }

    public function testReconstitutionKeepsTheStoredFingerprintWithoutRehashing(): void
    {
        $stored = hash('sha256', 'abc');

        self::assertSame($stored, PresentedTokenFingerprint::reconstitute($stored)->toString());
        self::assertTrue(PresentedTokenFingerprint::reconstitute($stored)->equals(PresentedTokenFingerprint::fromPresented('abc')));
    }

    #[DataProvider('malformedFingerprints')]
    public function testReconstitutionRejectsAnythingButAFingerprint(string $stored): void
    {
        $this->expectException(InvalidValueException::class);

        PresentedTokenFingerprint::reconstitute($stored);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedFingerprints(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'trailing newline' => [str_repeat('a', 64) . "\n"];
    }
}
