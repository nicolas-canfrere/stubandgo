<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\OrganizationRepositoryInterface;

final readonly class ListOrganizationsHandler
{
    public function __construct(private OrganizationRepositoryInterface $organizations)
    {
    }

    /**
     * @return list<OrganizationAdminView>
     */
    public function __invoke(ListOrganizations $query): array
    {
        return array_map(OrganizationAdminView::fromOrganization(...), $this->organizations->listByName());
    }
}
