<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\ValueObject;

use Money\Money;

final readonly class OrderAmounts
{
    private function __construct(
        private Money $total,
        private Money $commission,
        private Money $net,
    ) {
    }

    public static function compute(Money $total, CommissionRate $rate): self
    {
        $commission = $rate->applyTo($total);

        return new self($total, $commission, $total->subtract($commission));
    }

    /**
     * Rebuilds persisted amounts without recomputing them.
     */
    public static function of(Money $total, Money $commission, Money $net): self
    {
        return new self($total, $commission, $net);
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function commission(): Money
    {
        return $this->commission;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function equals(self $other): bool
    {
        return $this->total->equals($other->total)
            && $this->commission->equals($other->commission)
            && $this->net->equals($other->net);
    }
}
