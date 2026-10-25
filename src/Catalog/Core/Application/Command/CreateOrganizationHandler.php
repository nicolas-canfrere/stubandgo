<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\Exception\OrganizationNameTakenException;
use App\Catalog\Core\Domain\Exception\OrganizationSlugTakenException;
use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;

final readonly class CreateOrganizationHandler
{
    public function __construct(private OrganizationRepositoryInterface $organizations)
    {
    }

    public function __invoke(CreateOrganization $command): void
    {
        $name = OrganizationName::fromString($command->name);
        $slug = Slug::fromString($command->slug);

        if (null !== $this->organizations->findBySlug($slug)) {
            throw OrganizationSlugTakenException::withSlug($slug);
        }

        if ($this->organizations->nameExists($name)) {
            throw OrganizationNameTakenException::withName($name);
        }

        $this->organizations->add(Organization::create(
            OrganizationId::fromString($command->id),
            $name,
            $slug,
            CommissionRate::fromBasisPoints($command->commissionBasisPoints),
            null === $command->keycloakOrganizationId ? null : KeycloakOrganizationId::fromString($command->keycloakOrganizationId),
        ));
    }
}
