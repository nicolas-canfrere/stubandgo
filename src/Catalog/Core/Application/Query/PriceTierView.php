<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\PriceTier;

final readonly class PriceTierView
{
    public function __construct(
        public string $priceTierId,
        public string $label,
        public int $priceCents,
    ) {
    }

    public static function fromPriceTier(PriceTier $tier): self
    {
        return new self($tier->id()->toString(), $tier->label()->toString(), (int) $tier->price()->getAmount());
    }
}
