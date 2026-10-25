<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;

enum MemberRole: string
{
    case Admin = 'admin';
    case Controller = 'controller';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidValueException('Member role must be "admin" or "controller".');
    }

    /**
     * Name of the Keycloak realm role granted to a member holding this role.
     */
    public function keycloakRealmRole(): string
    {
        return 'organization-' . $this->value;
    }
}
