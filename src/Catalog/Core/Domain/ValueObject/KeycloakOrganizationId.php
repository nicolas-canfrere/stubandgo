<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

final readonly class KeycloakOrganizationId
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidValueException('Keycloak organization identifier must be a UUID.');
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
