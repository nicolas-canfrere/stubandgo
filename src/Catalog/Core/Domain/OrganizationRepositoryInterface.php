<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;

interface OrganizationRepositoryInterface
{
    /**
     * Inserts a new organization.
     */
    public function add(Organization $organization): void;

    /**
     * Writes the status and the Keycloak identifier of an activated organization, and nothing else, only while it is
     * still pending: a commission rate changed since the organization was read is never reverted, and an organization
     * activated in the meantime keeps its Keycloak identifier.
     */
    public function recordActivation(Organization $organization): void;

    /**
     * Writes the commission rate of an organization, and nothing else: its status and Keycloak identifier stay as stored.
     */
    public function recordCommissionRate(Organization $organization): void;

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

    public function findByKeycloakId(KeycloakOrganizationId $keycloakId): ?Organization;

    public function nameExists(OrganizationName $name): bool;

    /**
     * Every organization, in the alphabetical order of their names.
     *
     * @return list<Organization>
     */
    public function listByName(): array;
}
