<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;

final class Organization
{
    private function __construct(
        private readonly OrganizationId $id,
        private readonly OrganizationName $name,
        private readonly Slug $slug,
        private CommissionRate $commissionRate,
        private OrganizationStatus $status,
        private ?KeycloakOrganizationId $keycloakId,
    ) {
    }

    /**
     * Active when it already exists in Keycloak, pending its provisioning otherwise.
     */
    public static function create(OrganizationId $id, OrganizationName $name, Slug $slug, CommissionRate $commissionRate, ?KeycloakOrganizationId $keycloakId = null): self
    {
        return new self($id, $name, $slug, $commissionRate, null === $keycloakId ? OrganizationStatus::PendingKeycloak : OrganizationStatus::Active, $keycloakId);
    }

    /**
     * Rebuilds a persisted organization.
     */
    public static function reconstitute(OrganizationId $id, OrganizationName $name, Slug $slug, CommissionRate $commissionRate, OrganizationStatus $status, ?KeycloakOrganizationId $keycloakId): self
    {
        return new self($id, $name, $slug, $commissionRate, $status, $keycloakId);
    }

    public function activate(KeycloakOrganizationId $keycloakId): void
    {
        if (OrganizationStatus::Active === $this->status) {
            throw new \LogicException(\sprintf('Organization "%s" is already active.', $this->id->toString()));
        }

        $this->status = OrganizationStatus::Active;
        $this->keycloakId = $keycloakId;
    }

    public function changeCommissionRate(CommissionRate $commissionRate): void
    {
        $this->commissionRate = $commissionRate;
    }

    public function id(): OrganizationId
    {
        return $this->id;
    }

    public function name(): OrganizationName
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function commissionRate(): CommissionRate
    {
        return $this->commissionRate;
    }

    public function status(): OrganizationStatus
    {
        return $this->status;
    }

    public function keycloakId(): ?KeycloakOrganizationId
    {
        return $this->keycloakId;
    }
}
