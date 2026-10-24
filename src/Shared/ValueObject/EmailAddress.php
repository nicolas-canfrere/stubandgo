<?php

declare(strict_types=1);

namespace App\Shared\ValueObject;

use App\Shared\Exception\InvalidValueException;

final readonly class EmailAddress
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidValueException(\sprintf('"%s" is not a valid email address.', $value));
        }

        return new self(strtolower($value));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
