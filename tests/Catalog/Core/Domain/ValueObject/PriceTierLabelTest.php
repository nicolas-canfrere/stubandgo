<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceTierLabelTest extends TestCase
{
    private const int MAX = 60;

    public function testAcceptsMaximumLength(): void
    {
        $value = str_repeat('a', self::MAX);

        self::assertSame($value, PriceTierLabel::fromString($value)->toString());
    }

    public function testCountsCharactersNotBytes(): void
    {
        $value = str_repeat('é', self::MAX);

        self::assertSame($value, PriceTierLabel::fromString($value)->toString());
    }

    public function testRejectsAboveMaximumLength(): void
    {
        $this->expectException(InvalidValueException::class);

        PriceTierLabel::fromString(str_repeat('a', self::MAX + 1));
    }

    public function testRejectsMultibyteAboveMaximumLength(): void
    {
        $this->expectException(InvalidValueException::class);

        PriceTierLabel::fromString(str_repeat('é', self::MAX + 1));
    }

    public function testMaximumLengthIsMeasuredAfterTrim(): void
    {
        $value = str_repeat('a', self::MAX);

        self::assertSame($value, PriceTierLabel::fromString("  $value \n")->toString());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Hello world', PriceTierLabel::fromString("  Hello world\t\n")->toString());
    }

    #[DataProvider('blankValues')]
    public function testRejectsBlank(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        PriceTierLabel::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankValues(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'whitespace characters' => ["\t\n \r"];
    }

    public function testErrorMessageDoesNotEchoInput(): void
    {
        $value = str_repeat('z', self::MAX + 1);

        try {
            PriceTierLabel::fromString($value);
            self::fail('Expected an exception.');
        } catch (InvalidValueException $e) {
            self::assertStringNotContainsString($value, $e->getMessage());
            self::assertSame('invalid_value', $e->errorCode());
        }
    }

    public function testEquals(): void
    {
        self::assertTrue(PriceTierLabel::fromString('one')->equals(PriceTierLabel::fromString(' one ')));
        self::assertFalse(PriceTierLabel::fromString('one')->equals(PriceTierLabel::fromString('two')));
        self::assertFalse(PriceTierLabel::fromString('two')->equals(PriceTierLabel::fromString('one')));
    }
}
