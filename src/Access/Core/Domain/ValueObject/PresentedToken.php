<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

/**
 * What a scanner presented, kept for the scan log: any string is accepted (a malformed token is still a scan)
 * and only its first characters are retained. It is not a credential check, see TicketToken.
 *
 * PostgreSQL text rejects invalid UTF-8 and NUL bytes, yet every scan must be recorded: invalid sequences are
 * scrubbed and NUL bytes become U+FFFD before the truncation.
 */
final readonly class PresentedToken
{
    private const int MAX_LENGTH = 128;
    private const string REPLACEMENT_CHARACTER = "\u{FFFD}";

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $storable = str_replace("\0", self::REPLACEMENT_CHARACTER, mb_scrub($value, 'UTF-8'));

        return new self(mb_substr($storable, 0, self::MAX_LENGTH, 'UTF-8'));
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
