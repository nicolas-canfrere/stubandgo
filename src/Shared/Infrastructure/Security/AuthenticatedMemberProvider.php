<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds the authenticated member from the claims of an access token the OIDC token handler has already validated
 * (signature, issuer, audience, expiry): nothing is looked up, the token is the only source.
 *
 * @implements AttributesBasedUserProviderInterface<AuthenticatedMember>
 */
final readonly class AuthenticatedMemberProvider implements AttributesBasedUserProviderInterface
{
    public function __construct(
        #[Autowire(param: 'app.keycloak.organizations_issuer')]
        private string $organizationsIssuer,
        #[Autowire(param: 'app.keycloak.platform_issuer')]
        private string $platformIssuer,
    ) {
    }

    /**
     * @param array<mixed> $attributes the claims of the validated access token
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): AuthenticatedMember
    {
        if ('' === $identifier) {
            throw new UserNotFoundException('The access token names no user.');
        }

        return new AuthenticatedMember(
            $identifier,
            $this->realm($attributes['iss'] ?? null),
            $this->realmRoles($attributes['realm_access'] ?? null),
            $this->keycloakOrganizationId($attributes['organization'] ?? null),
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('The API is stateless: an authenticated member is never refreshed from a session.');
    }

    public function supportsClass(string $class): bool
    {
        return AuthenticatedMember::class === $class;
    }

    private function realm(mixed $issuer): MemberRealm
    {
        return match ($issuer) {
            $this->organizationsIssuer => MemberRealm::Organizations,
            $this->platformIssuer => MemberRealm::Platform,
            default => throw new UserNotFoundException('The access token comes from an unknown realm.'),
        };
    }

    /**
     * The "realm_access.roles" claim: the roles the member holds in their realm.
     *
     * @return list<string>
     */
    private function realmRoles(mixed $realmAccess): array
    {
        $roles = \is_array($realmAccess) ? $realmAccess['roles'] ?? null : null;

        return \is_array($roles) ? array_values(array_filter($roles, \is_string(...))) : [];
    }

    /**
     * The "organization" claim maps each organization alias to its attributes, among which its Keycloak id.
     * A member belongs to at most one organization: none, or several, means no organization.
     */
    private function keycloakOrganizationId(mixed $organizations): ?string
    {
        if (!\is_array($organizations) || 1 !== \count($organizations)) {
            return null;
        }

        $organization = reset($organizations);
        $id = \is_array($organization) ? $organization['id'] ?? null : null;

        return \is_string($id) ? $id : null;
    }
}
