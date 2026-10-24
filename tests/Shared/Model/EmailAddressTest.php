<?php

declare(strict_types=1);

namespace App\Tests\Shared\Model;

use App\Shared\InvalidValueException;
use App\Shared\Model\EmailAddress;
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
}
