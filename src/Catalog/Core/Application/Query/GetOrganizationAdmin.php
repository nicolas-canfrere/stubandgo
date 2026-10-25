<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<OrganizationAdminView>
 */
final readonly class GetOrganizationAdmin implements QueryInterface
{
    public function __construct(public string $organizationId)
    {
    }
}
