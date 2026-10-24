<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use OpenApi\Attributes as OA;

/**
 * Structure of one order line in the request body; quantity rules belong to the domain.
 */
final readonly class OrderLineRequest
{
    public function __construct(
        #[OA\Property(description: 'ULID of the price tier.')]
        public string $priceTierId,
        #[OA\Property(example: 2)]
        public int $quantity,
    ) {
    }
}
