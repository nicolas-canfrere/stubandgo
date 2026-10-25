<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Shared\Identity\OrganizationId;

final readonly class ChangeCommissionRateHandler
{
    public function __construct(private OrganizationRepositoryInterface $organizations)
    {
    }

    public function __invoke(ChangeCommissionRate $command): void
    {
        $organization = $this->organizations->get(OrganizationId::fromString($command->organizationId));
        $organization->changeCommissionRate(CommissionRate::fromBasisPoints($command->commissionBasisPoints));
        $this->organizations->recordCommissionRate($organization);
    }
}
