<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;

final readonly class Organization
{
    private function __construct(
        private OrganizationId $id,
        private OrganizationName $name,
        private Slug $slug,
        private CommissionRate $commissionRate,
        private ?KeycloakOrganizationId $keycloakId,
    ) {
    }

    public static function create(OrganizationId $id, OrganizationName $name, Slug $slug, CommissionRate $commissionRate, ?KeycloakOrganizationId $keycloakId = null): self
    {
        return new self($id, $name, $slug, $commissionRate, $keycloakId);
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

    public function keycloakId(): ?KeycloakOrganizationId
    {
        return $this->keycloakId;
    }
}
