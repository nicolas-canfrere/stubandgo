<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<list<OrganizationAdminView>>
 */
final readonly class ListOrganizations implements QueryInterface
{
}
