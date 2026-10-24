<?php

declare(strict_types=1);

namespace App\Shared\Identity;

use App\Shared\Exception\InvalidValueException;
use Symfony\Component\Uid\Ulid;

abstract readonly class AbstractUlidId
{
    final private function __construct(private string $value)
    {
    }

    final public static function fromString(string $value): static
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidValueException(\sprintf('"%s" is not a valid ULID.', $value));
        }

        return new static(Ulid::fromString($value)->toBase32());
    }

    final public static function generate(): static
    {
        return new static((string) new Ulid());
    }

    final public function toString(): string
    {
        return $this->value;
    }

    final public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }
}
