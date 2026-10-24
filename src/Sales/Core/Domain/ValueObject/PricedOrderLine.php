<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;
use App\Shared\ValueObject\OrderLine;
use Money\Currency;
use Money\Money;

final readonly class PricedOrderLine
{
    public function __construct(
        private OrderLine $line,
        private Money $unitPrice,
    ) {
        if (!$unitPrice->getCurrency()->equals(new Currency('EUR'))) {
            throw new InvalidValueException('Unit price must be in EUR.');
        }

        if ($unitPrice->isNegative()) {
            throw new InvalidValueException('Unit price must not be negative.');
        }
    }

    public function line(): OrderLine
    {
        return $this->line;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multiply($this->line->quantity->toInt());
    }

    public function equals(self $other): bool
    {
        return $this->line->equals($other->line) && $this->unitPrice->equals($other->unitPrice);
    }
}
