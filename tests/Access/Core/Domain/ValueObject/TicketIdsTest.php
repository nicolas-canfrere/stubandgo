<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Access\Core\Domain\ValueObject\TicketId;
use PHPUnit\Framework\TestCase;

final class TicketIdsTest extends TestCase
{
    public function testTicketIdRoundTrips(): void
    {
        $id = TicketId::generate();

        self::assertTrue($id->equals(TicketId::fromString($id->toString())));
    }

    public function testScanAttemptIdRoundTrips(): void
    {
        $id = ScanAttemptId::generate();

        self::assertTrue($id->equals(ScanAttemptId::fromString($id->toString())));
    }

    public function testIdsOfDifferentKindsAreNotEqual(): void
    {
        $ticketId = TicketId::generate();

        self::assertFalse($ticketId->equals(ScanAttemptId::fromString($ticketId->toString())));
    }
}
