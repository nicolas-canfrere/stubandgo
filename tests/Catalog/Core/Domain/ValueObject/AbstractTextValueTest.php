<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbstractTextValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unicodeBlankValues(): iterable
    {
        yield 'no-break space' => ["\u{00A0}"];
        yield 'ideographic space' => ["\u{3000}"];
        yield 'zero width space' => ["\u{200B}"];
        yield 'mixed' => ["\u{00A0}\u{3000} \t\u{2028}"];
        yield 'control character' => ["\u{0000}\u{0007}"];
    }

    #[DataProvider('unicodeBlankValues')]
    public function testRejectsUnicodeBlank(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        EventTitle::fromString($value);
    }

    public function testTrimsUnicodeWhitespaceAroundValue(): void
    {
        self::assertSame('Concert', EventTitle::fromString("\u{00A0}\u{3000}Concert\u{00A0} ")->toString());
    }

    public function testKeepsInnerWhitespace(): void
    {
        self::assertSame("a\u{00A0}b", EventTitle::fromString("a\u{00A0}b")->toString());
    }

    public function testRejectsInvalidUtf8WithoutEchoingInput(): void
    {
        try {
            EventTitle::fromString("\xC3\x28");
            self::fail('Expected an exception.');
        } catch (InvalidValueException $e) {
            self::assertStringNotContainsString("\xC3", $e->getMessage());
            self::assertSame('Event title must be valid UTF-8.', $e->getMessage());
        }
    }

    public function testDifferentTypesWithSameTextAreNotEqual(): void
    {
        self::assertFalse(EventTitle::fromString('x')->equals(Venue::fromString('x')));
        self::assertFalse(Venue::fromString('x')->equals(EventTitle::fromString('x')));
    }
}
