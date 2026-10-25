<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Organization;

final readonly class OrganizationAdminView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public int $commissionBasisPoints,
        public string $status,
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        return new self(
            $organization->id()->toString(),
            $organization->name()->toString(),
            $organization->slug()->toString(),
            $organization->commissionRate()->toBasisPoints(),
            $organization->status()->value,
        );
    }
}
