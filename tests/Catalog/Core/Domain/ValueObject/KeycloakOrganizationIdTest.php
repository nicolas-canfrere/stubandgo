<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeycloakOrganizationIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['demo-rock-asso'];
        yield 'too short' => ['0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a0'];
        yield 'trailing newline' => ["0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01\n"];
        yield 'leading space' => [' 0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01'];
    }

    public function testAcceptsAUuid(): void
    {
        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01', KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01')->toString());
    }

    public function testNormalizesToLowercase(): void
    {
        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01', KeycloakOrganizationId::fromString('0F8C4F8E-3C7A-4B52-9A51-6D1F2B7C0A01')->toString());
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(string $value): void
    {
        $this->expectException(InvalidValueException::class);

        KeycloakOrganizationId::fromString($value);
    }

    public function testEquals(): void
    {
        $id = KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01');

        self::assertTrue($id->equals(KeycloakOrganizationId::fromString('0F8C4F8E-3C7A-4B52-9A51-6D1F2B7C0A01')));
        self::assertFalse($id->equals(KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02')));
    }
}
