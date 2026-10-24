<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain\ValueObject;

use App\Sales\Core\Domain\ValueObject\CardNumber;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardNumberTest extends TestCase
{
    public function testStripsSpaces(): void
    {
        self::assertSame('4242424242424242', CardNumber::fromString('4242 4242 4242 4242')->digits());
    }

    #[DataProvider('validLengths')]
    public function testAcceptsBetween13And19Digits(string $number): void
    {
        self::assertSame($number, CardNumber::fromString($number)->digits());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validLengths(): iterable
    {
        yield '13 digits' => ['1234567890123'];
        yield '19 digits' => ['1234567890123456789'];
    }

    #[DataProvider('invalidNumbers')]
    public function testRejectsInvalidNumbersWithoutEchoingThem(string $number): void
    {
        try {
            CardNumber::fromString($number);
            self::fail('An invalid card number must be rejected.');
        } catch (InvalidValueException $e) {
            self::assertSame('Card number must contain between 13 and 19 digits.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'too short' => ['123456789012'];
        yield 'too long' => ['12345678901234567890'];
        yield 'letters' => ['4242 4242 4242 424a'];
        yield 'dashes' => ['4242-4242-4242-4242'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["4242424242424242\n"];
        yield 'leading garbage' => ['x4242424242424242'];
    }

    public function testEquals(): void
    {
        self::assertTrue(CardNumber::fromString('4242424242424242')->equals(CardNumber::fromString('4242 4242 4242 4242')));
        self::assertFalse(CardNumber::fromString('4242424242424242')->equals(CardNumber::fromString('4242424242424241')));
    }

    public function testIsNotStringable(): void
    {
        self::assertFalse(new \ReflectionClass(CardNumber::class)->hasMethod('__toString'));
    }
}
