<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain\ValueObject;

use App\Sales\Core\Domain\Exception\UnknownPriceTierException;
use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\SaleTerms;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class SaleTermsTest extends TestCase
{
    public function testExposesItsState(): void
    {
        $eventId = EventId::generate();
        $tierId = PriceTierId::generate();
        $rate = CommissionRate::fromBasisPoints(250);
        $startsAt = new \DateTimeImmutable('2026-12-31T20:30:00+01:00');

        $terms = SaleTerms::of($eventId, true, $startsAt, $rate, [$tierId->toString() => Money::EUR(1500)]);

        self::assertSame($eventId, $terms->eventId());
        self::assertTrue($terms->isPublished());
        self::assertSame('2026-12-31T19:30:00+00:00', $terms->startsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $terms->startsAt()->getTimezone()->getName());
        self::assertSame($rate, $terms->commissionRate());
        self::assertTrue(Money::EUR(1500)->equals($terms->priceOf($tierId)));
    }

    public function testUnpublishedFlagIsKept(): void
    {
        self::assertFalse(self::terms(false, [])->isPublished());
    }

    public function testPriceOfUnknownTierThrows(): void
    {
        $this->expectException(UnknownPriceTierException::class);

        self::terms(true, [PriceTierId::generate()->toString() => Money::EUR(100)])->priceOf(PriceTierId::generate());
    }

    /**
     * @param array<string, Money> $prices
     */
    private static function terms(bool $published, array $prices): SaleTerms
    {
        return SaleTerms::of(EventId::generate(), $published, new \DateTimeImmutable('2030-01-01T00:00:00Z'), CommissionRate::fromBasisPoints(0), $prices);
    }
}
