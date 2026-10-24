<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

/**
 * A ticket token is a bearer credential (32 random bytes, base64url): the exception raised on a bad input
 * never contains it.
 */
final readonly class TicketToken
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $value)) {
            throw new InvalidValueException('Ticket token must be 43 base64url characters.');
        }

        return new self($value);
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
