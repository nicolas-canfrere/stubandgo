<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ValueObject\EventDisplayInfo;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\PriceTierId;
use PHPUnit\Framework\TestCase;

final class EventDisplayInfoTest extends TestCase
{
    public function testExposesItsState(): void
    {
        $tierId = PriceTierId::generate();

        $info = EventDisplayInfo::of('Concert', 'Olympia', new \DateTimeImmutable('2026-12-31T20:30:00+01:00'), [$tierId->toString() => 'Standard']);

        self::assertSame('Concert', $info->title());
        self::assertSame('Olympia', $info->venue());
        self::assertSame('2026-12-31T19:30:00+00:00', $info->startsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $info->startsAt()->getTimezone()->getName());
        self::assertSame('Standard', $info->labelOf($tierId));
    }

    public function testLabelOfUnknownTierThrows(): void
    {
        $this->expectException(InvalidValueException::class);

        self::info('Concert', 'Olympia', [])->labelOf(PriceTierId::generate());
    }

    public function testRejectsABlankTitle(): void
    {
        $this->expectException(InvalidValueException::class);

        self::info(" \t", 'Olympia', []);
    }

    public function testRejectsABlankVenue(): void
    {
        $this->expectException(InvalidValueException::class);

        self::info('Concert', "  \n", []);
    }

    public function testEquals(): void
    {
        $tierId = PriceTierId::generate()->toString();
        $at = new \DateTimeImmutable('2030-01-01T00:00:00Z');
        $info = EventDisplayInfo::of('Concert', 'Olympia', $at, [$tierId => 'Standard']);

        self::assertTrue($info->equals(EventDisplayInfo::of('Concert', 'Olympia', $at->setTimezone(new \DateTimeZone('Europe/Paris')), [$tierId => 'Standard'])));
        self::assertFalse($info->equals(EventDisplayInfo::of('Other', 'Olympia', $at, [$tierId => 'Standard'])));
        self::assertFalse($info->equals(EventDisplayInfo::of('Concert', 'Bercy', $at, [$tierId => 'Standard'])));
        self::assertFalse($info->equals(EventDisplayInfo::of('Concert', 'Olympia', $at->modify('+1 second'), [$tierId => 'Standard'])));
        self::assertFalse($info->equals(EventDisplayInfo::of('Concert', 'Olympia', $at, [$tierId => 'Reduced'])));
    }

    /**
     * @param array<string, string> $labels
     */
    private static function info(string $title, string $venue, array $labels): EventDisplayInfo
    {
        return EventDisplayInfo::of($title, $venue, new \DateTimeImmutable('2030-01-01T00:00:00Z'), $labels);
    }
}
