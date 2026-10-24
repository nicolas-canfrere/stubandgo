<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain;

use App\Access\Core\Domain\AdmissionRight;
use App\Access\Core\Domain\Ticket;
use App\Access\Core\Domain\ValueObject\TicketId;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    public function testAdmissionRightExposesItsState(): void
    {
        $token = TicketToken::fromString(str_repeat('a', 43));
        $eventId = EventId::generate();

        $unused = new AdmissionRight($token, $eventId);
        self::assertSame($token, $unused->token());
        self::assertSame($eventId, $unused->eventId());
        self::assertNull($unused->usedAt());
        self::assertFalse($unused->isUsed());
    }

    public function testUsedAdmissionRightIsNormalizedToUtc(): void
    {
        $right = new AdmissionRight(TicketToken::fromString(str_repeat('a', 43)), EventId::generate(), new \DateTimeImmutable('2026-12-31T20:30:00+01:00'));

        self::assertTrue($right->isUsed());
        self::assertNotNull($right->usedAt());
        self::assertSame('2026-12-31T19:30:00+00:00', $right->usedAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $right->usedAt()->getTimezone()->getName());
    }

    public function testTicketExposesItsState(): void
    {
        $id = TicketId::generate();
        $tierId = PriceTierId::generate();
        $right = new AdmissionRight(TicketToken::fromString(str_repeat('a', 43)), EventId::generate());

        $ticket = new Ticket($id, $tierId, $right);

        self::assertSame($id, $ticket->id());
        self::assertSame($tierId, $ticket->priceTierId());
        self::assertSame($right, $ticket->admissionRight());
    }
}
