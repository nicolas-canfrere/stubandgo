<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Platform;

use OpenApi\Attributes as OA;

/**
 * Structure of the commission rate body: the 0 to 10000 range is checked by the domain.
 */
final readonly class ChangeCommissionRateRequest
{
    public function __construct(
        #[OA\Property(description: 'Commission of the platform on each sale, in basis points (0 to 10000).')]
        public int $commissionBasisPoints,
    ) {
    }
}
