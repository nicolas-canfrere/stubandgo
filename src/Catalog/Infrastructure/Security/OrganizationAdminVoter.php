<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Security;

use App\Catalog\Core\Application\Query\GetMemberOrganization;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\MemberRealm;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants the back-office of an organization to its administrators, and to no one else.
 *
 * There is no subject: the organization is the one of the member, which must exist in stubandgo (a member of a Keycloak
 * organization that stubandgo does not know has nothing to administer). Every back-office route without an
 * organization identifier asks for this attribute.
 *
 * @extends Voter<string, null>
 */
final class OrganizationAdminVoter extends Voter
{
    public const string ATTRIBUTE = 'CATALOG_ORGANIZATION_ADMIN';

    private const string ADMIN_ROLE = 'organization-admin';

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $member = $token->getUser();
        if (!$member instanceof AuthenticatedMember
            || MemberRealm::Organizations !== $member->realm()
            || !$member->hasRealmRole(self::ADMIN_ROLE)) {
            return false;
        }

        $keycloakOrganizationId = $member->keycloakOrganizationId();

        return null !== $keycloakOrganizationId
            && null !== $this->queryBus->ask(new GetMemberOrganization($keycloakOrganizationId));
    }
}
