<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VenueTest extends TestCase
{
    private const int MAX = 200;

    public function testAcceptsMaximumLength(): void
    {
        $value = str_repeat('a', self::MAX);

        self::assertSame($value, Venue::fromString($value)->toString());
    }

    public function testCountsCharactersNotBytes(): void
    {
        $value = str_repeat('é', self::MAX);

        self::assertSame($value, Venue::fromString($value)->toString());
    }

    public function testRejectsAboveMaximumLength(): void
    {
        $this->expectException(InvalidValueException::class);

        Venue::fromString(str_repeat('a', self::MAX + 1));
    }

    public function testRejectsMultibyteAboveMaximumLength(): void
    {
        $this->expectException(InvalidValueException::class);

        Venue::fromString(str_repeat('é', self::MAX + 1));
    }

    public function testMaximumLengthIsMeasuredAfterTrim(): void
    {
        $value = str_repeat('a', self::MAX);

        self::assertSame($value, Venue::fromString("  $value \n")->toString());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Hello world', Venue::fromString("  Hello world\t\n")->toString());
    }

    #[DataProvider('blankValues')]
    public function testRejectsBlank(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        Venue::fromString($value);
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
            Venue::fromString($value);
            self::fail('Expected an exception.');
        } catch (InvalidValueException $e) {
            self::assertStringNotContainsString($value, $e->getMessage());
            self::assertSame('invalid_value', $e->errorCode());
        }
    }

    public function testEquals(): void
    {
        self::assertTrue(Venue::fromString('one')->equals(Venue::fromString(' one ')));
        self::assertFalse(Venue::fromString('one')->equals(Venue::fromString('two')));
        self::assertFalse(Venue::fromString('two')->equals(Venue::fromString('one')));
    }
}
