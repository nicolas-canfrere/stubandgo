<?php

declare(strict_types=1);

namespace App\Shared\ValueObject;

use App\Shared\Identity\PriceTierId;

final readonly class OrderLine
{
    public function __construct(
        public PriceTierId $priceTierId,
        public Quantity $quantity,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->priceTierId->equals($other->priceTierId) && $this->quantity->equals($other->quantity);
    }
}
