<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

final readonly class CommissionRate
{
    private const int MAX_BASIS_POINTS = 10000;

    private function __construct(private int $basisPoints)
    {
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new InvalidValueException(\sprintf('Commission rate must be between 0 and %d basis points, %d given.', self::MAX_BASIS_POINTS, $basisPoints));
        }

        return new self($basisPoints);
    }

    public function toBasisPoints(): int
    {
        return $this->basisPoints;
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }
}
