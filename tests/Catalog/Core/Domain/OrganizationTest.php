<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationStatus;
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
        self::assertSame(OrganizationStatus::PendingKeycloak, $organization->status());
    }

    public function testCreateKeepsItsKeycloakId(): void
    {
        $keycloakId = KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01');

        $organization = Organization::create(OrganizationId::generate(), OrganizationName::fromString('Rock Asso'), Slug::fromString('rock-asso'), CommissionRate::fromBasisPoints(500), $keycloakId);

        self::assertSame($keycloakId, $organization->keycloakId());
        self::assertSame(OrganizationStatus::Active, $organization->status());
    }

    public function testActivateRecordsTheKeycloakId(): void
    {
        $keycloakId = KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01');
        $organization = Organization::create(OrganizationId::generate(), OrganizationName::fromString('Rock Asso'), Slug::fromString('rock-asso'), CommissionRate::fromBasisPoints(500));

        $organization->activate($keycloakId);

        self::assertSame(OrganizationStatus::Active, $organization->status());
        self::assertSame($keycloakId, $organization->keycloakId());
    }

    public function testActivatingTwiceIsRejected(): void
    {
        $organization = Organization::create(OrganizationId::generate(), OrganizationName::fromString('Rock Asso'), Slug::fromString('rock-asso'), CommissionRate::fromBasisPoints(500));
        $organization->activate(KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01'));

        $this->expectException(\LogicException::class);

        $organization->activate(KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02'));
    }

    public function testChangeCommissionRate(): void
    {
        $organization = Organization::create(OrganizationId::generate(), OrganizationName::fromString('Rock Asso'), Slug::fromString('rock-asso'), CommissionRate::fromBasisPoints(500));
        $rate = CommissionRate::fromBasisPoints(750);

        $organization->changeCommissionRate($rate);

        self::assertSame($rate, $organization->commissionRate());
    }

    public function testReconstituteRestoresTheStatus(): void
    {
        $organization = Organization::reconstitute(OrganizationId::generate(), OrganizationName::fromString('Rock Asso'), Slug::fromString('rock-asso'), CommissionRate::fromBasisPoints(500), OrganizationStatus::PendingKeycloak, null);

        self::assertSame(OrganizationStatus::PendingKeycloak, $organization->status());
        self::assertNull($organization->keycloakId());
    }
}
