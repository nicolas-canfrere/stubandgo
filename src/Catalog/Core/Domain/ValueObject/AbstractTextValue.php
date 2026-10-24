<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

abstract readonly class AbstractTextValue
{
    final private function __construct(private string $value)
    {
    }

    final public static function fromString(string $value): static
    {
        $value = trim($value);

        if ('' === $value) {
            throw new InvalidValueException(\sprintf('%s must not be blank.', static::label()));
        }

        if (mb_strlen($value) > static::maxLength()) {
            throw new InvalidValueException(\sprintf('%s must not exceed %d characters.', static::label(), static::maxLength()));
        }

        return new static($value);
    }

    final public function toString(): string
    {
        return $this->value;
    }

    final public function equals(self $other): bool
    {
        return static::class === $other::class && $this->value === $other->value;
    }

    abstract protected static function label(): string;

    abstract protected static function maxLength(): int;
}
