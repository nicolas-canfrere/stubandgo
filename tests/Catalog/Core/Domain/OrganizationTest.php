<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
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
    }
}
