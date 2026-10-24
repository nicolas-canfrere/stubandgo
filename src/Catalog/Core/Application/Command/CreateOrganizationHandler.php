<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
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
        $this->organizations->save(Organization::create(
            OrganizationId::fromString($command->id),
            OrganizationName::fromString($command->name),
            Slug::fromString($command->slug),
            CommissionRate::fromBasisPoints($command->commissionBasisPoints),
        ));
    }
}
