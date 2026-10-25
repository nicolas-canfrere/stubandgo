<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use OpenApi\Attributes as OA;

/**
 * Structure of one price tier in the event body: the label and the price are checked by the domain.
 */
final readonly class PriceTierRequest
{
    public function __construct(
        #[OA\Property(description: 'Label of the price tier.', example: 'Plein tarif')]
        public string $label,
        #[OA\Property(description: 'Price in euro cents.', example: 2500)]
        public int $priceCents,
        #[OA\Property(description: 'ULID of an existing tier of the event, to keep it; null or omitted, a new tier is created. Any other identifier is refused (422 /problems/invalid_value).', nullable: true)]
        public ?string $id = null,
    ) {
    }
}
