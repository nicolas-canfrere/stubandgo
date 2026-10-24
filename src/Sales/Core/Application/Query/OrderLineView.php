<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

final readonly class OrderLineView
{
    public function __construct(
        public string $priceTierId,
        public int $quantity,
        public int $unitPriceCents,
    ) {
    }
}
