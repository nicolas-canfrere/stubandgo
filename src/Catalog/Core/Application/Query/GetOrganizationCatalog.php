<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

final readonly class GetOrganizationCatalog
{
    public function __construct(public string $slug)
    {
    }
}
