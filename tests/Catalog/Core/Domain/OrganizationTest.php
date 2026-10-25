<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    public function testCreateExposesItsState(): void
    {
        $id = OrganizationId::generate();
        $name = OrganizationName::fromString('Rock Asso');
        $slug = Slug::fromString('rock-asso');
        $rate = CommissionRate::fromBasisPoints(500);

        $organization = Organization::create($id, $name, $slug, $rate);

        self::assertSame($id, $organization->id());
        self::assertSame($name, $organization->name());
        self::assertSame($slug, $organization->slug());
        self::assertSame($rate, $organization->commissionRate());
        self::assertNull($organization->keycloakId());
    }

    public function testCreateKeepsItsKeycloakId(): void
    {
        $keycloakId = KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01');

        $organization = Organization::create(OrganizationId::generate(), OrganizationName::fromString('Rock Asso'), Slug::fromString('rock-asso'), CommissionRate::fromBasisPoints(500), $keycloakId);

        self::assertSame($keycloakId, $organization->keycloakId());
    }
}
