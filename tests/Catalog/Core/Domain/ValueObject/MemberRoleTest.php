<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\TestCase;

final class MemberRoleTest extends TestCase
{
    public function testFromString(): void
    {
        self::assertSame(MemberRole::Admin, MemberRole::fromString('admin'));
        self::assertSame(MemberRole::Controller, MemberRole::fromString('controller'));
    }

    public function testKeycloakRealmRole(): void
    {
        self::assertSame('organization-admin', MemberRole::Admin->keycloakRealmRole());
        self::assertSame('organization-controller', MemberRole::Controller->keycloakRealmRole());
    }

    public function testUnknownRoleIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        MemberRole::fromString('owner');
    }
}
