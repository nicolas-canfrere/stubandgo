<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;

interface OrganizationRepositoryInterface
{
    public function save(Organization $organization): void;

    /**
     * @throws OrganizationNotFoundException
     */
    public function get(OrganizationId $id): Organization;

    /**
     * @throws OrganizationNotFoundException
     */
    public function getBySlug(Slug $slug): Organization;
}
