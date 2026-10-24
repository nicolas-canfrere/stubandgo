<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<OrganizationCatalogView>
 */
final readonly class GetOrganizationCatalog implements QueryInterface
{
    public function __construct(public string $slug)
    {
    }
}
