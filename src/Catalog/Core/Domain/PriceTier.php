<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\PriceTierId;
use Money\Currency;
use Money\Money;

final readonly class PriceTier
{
    public function __construct(
        private PriceTierId $id,
        private PriceTierLabel $label,
        private Money $price,
    ) {
        if (!$price->getCurrency()->equals(new Currency('EUR'))) {
            throw new InvalidValueException('Price tier price must be in EUR.');
        }

        if ($price->isNegative()) {
            throw new InvalidValueException('Price tier price must not be negative.');
        }
    }

    public function id(): PriceTierId
    {
        return $this->id;
    }

    public function label(): PriceTierLabel
    {
        return $this->label;
    }

    public function price(): Money
    {
        return $this->price;
    }
}
