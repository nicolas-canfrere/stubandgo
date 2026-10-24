<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\ValueObject;

use App\Sales\Core\Domain\Exception\UnknownPriceTierException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use Money\Money;

/**
 * What the Sales context needs to know about an event to sell it.
 */
final readonly class SaleTerms
{
    /**
     * @param array<string, Money> $unitPrices unit price of each price tier, keyed by price tier identifier
     */
    private function __construct(
        private EventId $eventId,
        private bool $published,
        private \DateTimeImmutable $startsAt,
        private CommissionRate $commissionRate,
        private array $unitPrices,
    ) {
    }

    /**
     * @param array<string, Money> $unitPrices unit price of each price tier, keyed by price tier identifier
     */
    public static function of(
        EventId $eventId,
        bool $published,
        \DateTimeImmutable $startsAt,
        CommissionRate $commissionRate,
        array $unitPrices,
    ): self {
        return new self($eventId, $published, $startsAt->setTimezone(new \DateTimeZone('UTC')), $commissionRate, $unitPrices);
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function commissionRate(): CommissionRate
    {
        return $this->commissionRate;
    }

    /**
     * @throws UnknownPriceTierException
     */
    public function priceOf(PriceTierId $priceTierId): Money
    {
        return $this->unitPrices[$priceTierId->toString()] ?? throw UnknownPriceTierException::withId($priceTierId);
    }
}
