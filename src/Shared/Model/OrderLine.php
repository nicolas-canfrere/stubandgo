<?php

declare(strict_types=1);

namespace App\Shared\Model;

use App\Shared\Identity\PriceTierId;

final readonly class OrderLine
{
    public function __construct(
        public PriceTierId $priceTierId,
        public Quantity $quantity,
    ) {
    }
}
