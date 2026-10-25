<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

final readonly class EventSalesView
{
    /**
     * @param list<PriceTierSalesView> $priceTiers every price tier of the event, in the order of the event
     */
    public function __construct(
        public string $eventId,
        public int $seatsRemaining,
        public int $ticketsSold,
        public int $totalCents,
        public int $commissionCents,
        public int $netCents,
        public array $priceTiers,
    ) {
    }
}
