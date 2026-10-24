<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ValueObject\PresentedToken;
use PHPUnit\Framework\TestCase;

final class PresentedTokenTest extends TestCase
{
    public function testKeepsAnyShortString(): void
    {
        self::assertSame('', PresentedToken::fromString('')->toString());
        self::assertSame('abc', PresentedToken::fromString('abc')->toString());
    }

    public function testKeepsAtMost128Characters(): void
    {
        self::assertSame(str_repeat('a', 128), PresentedToken::fromString(str_repeat('a', 128))->toString());
        self::assertSame(str_repeat('a', 128), PresentedToken::fromString(str_repeat('a', 500))->toString());
    }

    public function testTruncatesOnCharactersNotBytes(): void
    {
        self::assertSame(str_repeat('é', 128), PresentedToken::fromString(str_repeat('é', 200))->toString());
    }

    public function testEquals(): void
    {
        self::assertTrue(PresentedToken::fromString('abc')->equals(PresentedToken::fromString('abc')));
        self::assertFalse(PresentedToken::fromString('abc')->equals(PresentedToken::fromString('abd')));
    }
}
