<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Infrastructure\RandomTicketTokenGenerator;
use PHPUnit\Framework\TestCase;

final class RandomTicketTokenGeneratorTest extends TestCase
{
    public function testTokensAreDistinctAndHold32RandomBytes(): void
    {
        $generator = new RandomTicketTokenGenerator();

        $first = $generator->generate()->toString();
        $second = $generator->generate()->toString();

        self::assertNotSame($first, $second);
        self::assertSame(43, \strlen($first));
        self::assertSame(32, \strlen((string) base64_decode(strtr($first, '-_', '+/') . '=', true)));
    }

    public function testTokensUseTheUrlSafeAlphabet(): void
    {
        $generator = new RandomTicketTokenGenerator();

        for ($i = 0; $i < 200; ++$i) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $generator->generate()->toString());
        }
    }
}
