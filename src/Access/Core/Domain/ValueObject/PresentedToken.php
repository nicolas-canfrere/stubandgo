<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

/**
 * What a scanner presented, kept for the scan log: any string is accepted (a malformed token is still a scan)
 * and only its first characters are retained. It is not a credential check, see TicketToken.
 */
final readonly class PresentedToken
{
    private const int MAX_LENGTH = 128;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        return new self(mb_substr($value, 0, self::MAX_LENGTH, 'UTF-8'));
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
