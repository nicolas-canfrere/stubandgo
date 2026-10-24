<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

/**
 * Structure of one order line in the request body; quantity rules belong to the domain.
 */
final readonly class OrderLineRequest
{
    public function __construct(
        public string $priceTierId,
        public int $quantity,
    ) {
    }
}
