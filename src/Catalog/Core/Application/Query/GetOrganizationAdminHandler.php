<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Shared\Identity\OrganizationId;

final readonly class GetOrganizationAdminHandler
{
    public function __construct(private OrganizationRepositoryInterface $organizations)
    {
    }

    public function __invoke(GetOrganizationAdmin $query): OrganizationAdminView
    {
        return OrganizationAdminView::fromOrganization($this->organizations->get(OrganizationId::fromString($query->organizationId)));
    }
}
