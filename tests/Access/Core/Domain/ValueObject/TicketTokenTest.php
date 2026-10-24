<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TicketTokenTest extends TestCase
{
    public function testAcceptsABase64UrlToken(): void
    {
        $value = 'Abc-_0123456789AbcdefghijklmnopqrstuvwxyzAB';

        self::assertSame($value, TicketToken::fromString($value)->toString());
    }

    public function testTokenRejects42Chars(): void
    {
        $this->expectException(InvalidValueException::class);

        TicketToken::fromString(str_repeat('a', 42));
    }

    public function testTokenRejects44Chars(): void
    {
        $this->expectException(InvalidValueException::class);

        TicketToken::fromString(str_repeat('a', 44));
    }

    public function testTokenRejectsInvalidAlphabet(): void
    {
        $this->expectException(InvalidValueException::class);

        TicketToken::fromString(str_repeat('a', 42) . '+');
    }

    public function testTokenRejectsATrailingNewline(): void
    {
        $this->expectException(InvalidValueException::class);

        TicketToken::fromString(str_repeat('a', 43) . "\n");
    }

    #[DataProvider('invalidTokens')]
    public function testRejectionNeverEchoesTheToken(string $token): void
    {
        try {
            TicketToken::fromString($token);
            self::fail('An invalid token must be rejected.');
        } catch (InvalidValueException $e) {
            self::assertSame('Ticket token must be 43 base64url characters.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'secret-looking' => ['SECRET-secret-SECRET-secret'];
        yield 'empty' => [''];
    }

    public function testEquals(): void
    {
        $a = TicketToken::fromString(str_repeat('a', 43));

        self::assertTrue($a->equals(TicketToken::fromString(str_repeat('a', 43))));
        self::assertFalse($a->equals(TicketToken::fromString(str_repeat('b', 43))));
    }
}
