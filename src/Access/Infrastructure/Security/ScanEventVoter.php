<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Security;

use App\Catalog\Core\Application\Published\EventOrganizationProviderInterface;
use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\MemberRealm;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants the scan of an event to the controllers of the organization that owns it, and to no one else.
 *
 * The subject is the raw event identifier of the URL: a malformed or unknown one is denied like the event of another
 * organization, so that a member never learns whether an event exists.
 *
 * @extends Voter<string, string>
 */
final class ScanEventVoter extends Voter
{
    public const string ATTRIBUTE = 'ACCESS_SCAN_EVENT';

    private const string CONTROLLER_ROLE = 'organization-controller';

    public function __construct(private readonly EventOrganizationProviderInterface $eventOrganizations)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && \is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $member = $token->getUser();
        if (!$member instanceof AuthenticatedMember
            || MemberRealm::Organizations !== $member->realm()
            || !$member->hasRealmRole(self::CONTROLLER_ROLE)) {
            return false;
        }

        $organization = $member->keycloakOrganizationId();

        return null !== $organization && $organization === $this->eventOrganizations->keycloakOrganizationIdOf($subject);
    }
}
