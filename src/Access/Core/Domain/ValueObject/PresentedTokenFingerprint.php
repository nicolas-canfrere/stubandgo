<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

/**
 * What the scan log keeps of a presented token: the SHA-256 of its bytes in lowercase hexadecimal (64 characters),
 * with no secret and no truncation. Any string, malformed ones included, has a fingerprint, so every scan can be
 * recorded; the token itself is never kept, since a scan of another event leaves it a valid bearer credential
 * (see TicketToken). A real token carries 256 bits of entropy: its fingerprint cannot be reversed.
 */
final readonly class PresentedTokenFingerprint
{
    private const string PATTERN = '/\A[0-9a-f]{64}\z/';

    private function __construct(private string $value)
    {
    }

    public static function fromPresented(string $token): self
    {
        return new self(hash('sha256', $token));
    }

    /**
     * Rebuilds a stored fingerprint as is, without hashing it again.
     *
     * @throws InvalidValueException when the value is not 64 lowercase hexadecimal characters
     */
    public static function reconstitute(string $fingerprint): self
    {
        if (1 !== preg_match(self::PATTERN, $fingerprint)) {
            throw new InvalidValueException('A token fingerprint is 64 lowercase hexadecimal characters.');
        }

        return new self($fingerprint);
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
