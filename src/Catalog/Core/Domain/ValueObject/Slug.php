<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

final readonly class Slug
{
    private const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/D';
    private const int MIN_LENGTH = 3;
    private const int MAX_LENGTH = 64;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $length = \strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidValueException(\sprintf('Slug must be %d to %d lowercase letters, digits or single hyphens between them.', self::MIN_LENGTH, self::MAX_LENGTH));
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
