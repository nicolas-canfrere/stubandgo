<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\Exception\OrganizationNotActiveException;
use App\Catalog\Core\Domain\OrganizationDirectoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Shared\Identity\OrganizationId;
use App\Shared\ValueObject\EmailAddress;

final readonly class InviteMemberHandler
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private OrganizationDirectoryInterface $directory,
    ) {
    }

    public function __invoke(InviteMember $command): void
    {
        $email = EmailAddress::fromString($command->email);
        $role = MemberRole::fromString($command->role);
        $organization = $this->organizations->get(OrganizationId::fromString($command->organizationId));

        $keycloakId = $organization->keycloakId() ?? throw OrganizationNotActiveException::withId($organization->id());

        $this->directory->inviteMember($keycloakId, $email, $role);
    }
}
