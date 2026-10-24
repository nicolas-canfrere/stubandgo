<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validSlugs(): iterable
    {
        yield 'minimum length' => ['abc'];
        yield 'maximum length' => [str_repeat('a', 64)];
        yield 'with digits' => ['rock-2026'];
        yield 'digits only' => ['123'];
        yield 'several hyphens' => ['a-b-c'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'empty' => [''];
        yield 'uppercase' => ['Rock'];
        yield 'space' => ['rock fest'];
        yield 'underscore' => ['rock_fest'];
        yield 'leading hyphen' => ['-rock'];
        yield 'trailing hyphen' => ['rock-'];
        yield 'double hyphen' => ['rock--fest'];
        yield 'accent' => ['rôck'];
        yield 'trailing newline' => ["rock\n"];
        yield 'leading newline' => ["\nrock"];
    }

    #[DataProvider('validSlugs')]
    public function testSlugAcceptsValidValue(string $value): void
    {
        self::assertSame($value, Slug::fromString($value)->toString());
    }

    #[DataProvider('invalidSlugs')]
    public function testSlugRejectsInvalidValue(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        Slug::fromString($value);
    }

    public function testSlugRejectsUppercaseAndSpaces(): void
    {
        foreach (['Rock', 'rock fest', 'ROCK FEST'] as $value) {
            try {
                Slug::fromString($value);
                self::fail(\sprintf('"%s" should be rejected.', $value));
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSlugEquals(): void
    {
        self::assertTrue(Slug::fromString('rock')->equals(Slug::fromString('rock')));
        self::assertFalse(Slug::fromString('rock')->equals(Slug::fromString('jazz')));
        self::assertFalse(Slug::fromString('jazz')->equals(Slug::fromString('rock')));
    }
}
