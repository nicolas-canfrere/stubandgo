<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

final readonly class PriceTierTerms
{
    public function __construct(
        public string $priceTierId,
        public int $priceCents,
    ) {
    }
}
