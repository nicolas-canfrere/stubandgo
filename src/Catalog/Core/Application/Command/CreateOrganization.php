<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

final readonly class CreateOrganization
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public int $commissionBasisPoints,
    ) {
    }
}
