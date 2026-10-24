<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

/**
 * A card number is sensitive: it is never stored, logged or echoed, and the
 * exception raised on a bad input never contains it.
 */
final readonly class CardNumber
{
    private function __construct(private string $digits)
    {
    }

    public static function fromString(string $value): self
    {
        $digits = str_replace(' ', '', $value);

        if (1 !== preg_match('/^\d{13,19}$/D', $digits)) {
            throw new InvalidValueException('Card number must contain between 13 and 19 digits.');
        }

        return new self($digits);
    }

    /**
     * The raw digits, to be handed to the payment gateway only.
     */
    public function digits(): string
    {
        return $this->digits;
    }

    public function equals(self $other): bool
    {
        return $this->digits === $other->digits;
    }
}
