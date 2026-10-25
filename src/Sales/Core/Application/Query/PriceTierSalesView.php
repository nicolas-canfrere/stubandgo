<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

final readonly class PriceTierSalesView
{
    public function __construct(
        public string $priceTierId,
        public string $label,
        public int $ticketsSold,
        public int $totalCents,
    ) {
    }
}
