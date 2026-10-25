<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Security;

use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\MemberRealm;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants the management of the organizations of the platform to its staff holding the platform-admin role, and to no one else.
 *
 * A member of an organization is refused whatever their roles: the role is only meaningful in the realm of the staff.
 *
 * @extends Voter<string, null>
 */
final class PlatformAdminVoter extends Voter
{
    public const string ATTRIBUTE = 'CATALOG_PLATFORM_ADMIN';

    private const string ADMIN_ROLE = 'platform-admin';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $member = $token->getUser();

        return $member instanceof AuthenticatedMember
            && MemberRealm::Platform === $member->realm()
            && $member->hasRealmRole(self::ADMIN_ROLE);
    }
}
