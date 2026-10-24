<?php

declare(strict_types=1);

namespace App\Tests\Shared\ValueObject;

use App\Shared\InvalidValueException;
use App\Shared\ValueObject\EmailAddress;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testEmailIsNormalisedToLowercase(): void
    {
        self::assertSame('ana@example.com', EmailAddress::fromString('Ana@Example.COM')->toString());
    }

    public function testEmailRejectsMissingAt(): void
    {
        $this->expectException(InvalidValueException::class);

        EmailAddress::fromString('ana.example.com');
    }

    public function testEmailEqualsAfterNormalisation(): void
    {
        self::assertTrue(EmailAddress::fromString('Ana@Example.COM')->equals(EmailAddress::fromString('ana@example.com')));
    }

    public function testEmailDiffersFromOtherEmail(): void
    {
        self::assertFalse(EmailAddress::fromString('ana@example.com')->equals(EmailAddress::fromString('bob@example.com')));
    }
}
