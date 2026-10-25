<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Organization;

final readonly class OrganizationReferenceView
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        return new self($organization->slug()->toString(), $organization->name()->toString());
    }
}
