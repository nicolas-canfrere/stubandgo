<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\OrganizationDirectoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationStatus;
use App\Shared\Identity\OrganizationId;

final readonly class ProvisionOrganizationHandler
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private OrganizationDirectoryInterface $directory,
    ) {
    }

    public function __invoke(ProvisionOrganization $command): void
    {
        $organization = $this->organizations->get(OrganizationId::fromString($command->organizationId));

        if (OrganizationStatus::Active === $organization->status()) {
            return;
        }

        $organization->activate($this->directory->ensureOrganization($organization->slug(), $organization->name()));
        $this->organizations->save($organization);
    }
}
