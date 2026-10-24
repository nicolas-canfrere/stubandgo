<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrganizationNameTest extends TestCase
{
    private const int MAX = 120;

    public function testAcceptsMaximumLength(): void
    {
        $value = str_repeat('a', self::MAX);

        self::assertSame($value, OrganizationName::fromString($value)->toString());
    }

    public function testCountsCharactersNotBytes(): void
    {
        $value = str_repeat('é', self::MAX);

        self::assertSame($value, OrganizationName::fromString($value)->toString());
    }

    public function testRejectsAboveMaximumLength(): void
    {
        $this->expectException(InvalidValueException::class);

        OrganizationName::fromString(str_repeat('a', self::MAX + 1));
    }

    public function testRejectsMultibyteAboveMaximumLength(): void
    {
        $this->expectException(InvalidValueException::class);

        OrganizationName::fromString(str_repeat('é', self::MAX + 1));
    }

    public function testMaximumLengthIsMeasuredAfterTrim(): void
    {
        $value = str_repeat('a', self::MAX);

        self::assertSame($value, OrganizationName::fromString("  $value \n")->toString());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Hello world', OrganizationName::fromString("  Hello world\t\n")->toString());
    }

    #[DataProvider('blankValues')]
    public function testRejectsBlank(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        OrganizationName::fromString($value);
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
            OrganizationName::fromString($value);
            self::fail('Expected an exception.');
        } catch (InvalidValueException $e) {
            self::assertStringNotContainsString($value, $e->getMessage());
            self::assertSame('invalid_value', $e->errorCode());
        }
    }

    public function testEquals(): void
    {
        self::assertTrue(OrganizationName::fromString('one')->equals(OrganizationName::fromString(' one ')));
        self::assertFalse(OrganizationName::fromString('one')->equals(OrganizationName::fromString('two')));
        self::assertFalse(OrganizationName::fromString('two')->equals(OrganizationName::fromString('one')));
    }
}
