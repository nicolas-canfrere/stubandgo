<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The member behind a validated Keycloak access token, rebuilt on each request (the API keeps no session).
 */
final readonly class AuthenticatedMember implements UserInterface
{
    /**
     * @param non-empty-string $keycloakUserId
     * @param list<string>     $realmRoles
     */
    public function __construct(
        private string $keycloakUserId,
        private MemberRealm $realm,
        private array $realmRoles,
        private ?string $keycloakOrganizationId,
    ) {
    }

    public function realm(): MemberRealm
    {
        return $this->realm;
    }

    public function hasRealmRole(string $role): bool
    {
        return \in_array($role, $this->realmRoles, true);
    }

    /**
     * @return list<string>
     */
    public function realmRoles(): array
    {
        return $this->realmRoles;
    }

    /**
     * The Keycloak id of the member's organization; null when the token names none, or more than one.
     */
    public function keycloakOrganizationId(): ?string
    {
        return $this->keycloakOrganizationId;
    }

    public function getUserIdentifier(): string
    {
        return $this->keycloakUserId;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }
}
