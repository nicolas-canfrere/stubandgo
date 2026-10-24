<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;
use Money\Money;

final readonly class CommissionRate
{
    private const int BASIS_POINTS_PER_UNIT = 10000;

    private function __construct(private int $basisPoints)
    {
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::BASIS_POINTS_PER_UNIT) {
            throw new InvalidValueException(\sprintf('Commission rate must be between 0 and %d basis points, %d given.', self::BASIS_POINTS_PER_UNIT, $basisPoints));
        }

        return new self($basisPoints);
    }

    public function toBasisPoints(): int
    {
        return $this->basisPoints;
    }

    public function applyTo(Money $total): Money
    {
        return $total->multiply((string) ($this->basisPoints / self::BASIS_POINTS_PER_UNIT), Money::ROUND_HALF_UP);
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }
}
