<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;

interface OrganizationRepositoryInterface
{
    /**
     * Inserts a new organization, or updates the status, Keycloak identifier and commission rate of a known one.
     */
    public function save(Organization $organization): void;

    /**
     * @throws OrganizationNotFoundException
     */
    public function get(OrganizationId $id): Organization;

    /**
     * @throws OrganizationNotFoundException
     */
    public function getBySlug(Slug $slug): Organization;

    public function find(OrganizationId $id): ?Organization;

    public function findBySlug(Slug $slug): ?Organization;

    public function nameExists(OrganizationName $name): bool;

    /**
     * Every organization, in the alphabetical order of their names.
     *
     * @return list<Organization>
     */
    public function listByName(): array;
}
