<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

/**
 * Sales of an event as recorded by its paid orders: sums of the amounts frozen on the orders, in euro cents.
 */
final readonly class EventSalesFigures
{
    /**
     * @param array<string, array{ticketsSold: int, totalCents: int}> $byPriceTier sales by price tier identifier, only for tiers that sold
     * @param int|null                                                $seatsRemaining seats still free, null when the event has no seat stock (never published)
     */
    public function __construct(
        public int $ticketsSold,
        public int $totalCents,
        public int $commissionCents,
        public int $netCents,
        public array $byPriceTier,
        public ?int $seatsRemaining,
    ) {
    }
}
