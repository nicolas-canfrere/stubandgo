<?php

declare(strict_types=1);

namespace App\Shared\Model;

use App\Shared\InvalidValueException;

final readonly class Capacity
{
    private function __construct(private int $value)
    {
    }

    public static function of(int $value): self
    {
        if ($value < 1) {
            throw new InvalidValueException(\sprintf('Capacity must be at least 1, %d given.', $value));
        }

        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }
}
