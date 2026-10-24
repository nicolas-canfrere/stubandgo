<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain;

use App\Access\Core\Domain\AdmissionRight;
use App\Access\Core\Domain\Ticket;
use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\ValueObject\TicketId;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class TicketBundleTest extends TestCase
{
    public function testIssuesOneTicketPerUnit(): void
    {
        $standard = PriceTierId::generate();
        $reduced = PriceTierId::generate();

        $bundle = TicketBundle::issue(
            OrderId::generate(),
            EventId::generate(),
            EmailAddress::fromString('buyer@example.com'),
            [new OrderLine($standard, Quantity::of(2)), new OrderLine($reduced, Quantity::of(1))],
            new FixedTicketTokenGenerator(),
        );

        $tickets = $bundle->tickets();
        self::assertCount(3, $tickets);
        self::assertTrue($standard->equals($tickets[0]->priceTierId()));
        self::assertTrue($standard->equals($tickets[1]->priceTierId()));
        self::assertTrue($reduced->equals($tickets[2]->priceTierId()));
        $tokens = array_map(static fn(Ticket $t): string => $t->admissionRight()->token()->toString(), $tickets);
        self::assertCount(3, array_unique($tokens));
        $ids = array_map(static fn(Ticket $t): string => $t->id()->toString(), $tickets);
        self::assertCount(3, array_unique($ids));
    }

    public function testEachTicketHasOneAdmissionRightForTheEvent(): void
    {
        $eventId = EventId::generate();

        $bundle = TicketBundle::issue(
            OrderId::generate(),
            $eventId,
            EmailAddress::fromString('buyer@example.com'),
            [new OrderLine(PriceTierId::generate(), Quantity::of(2))],
            new FixedTicketTokenGenerator(),
        );

        foreach ($bundle->tickets() as $ticket) {
            self::assertTrue($eventId->equals($ticket->admissionRight()->eventId()));
            self::assertFalse($ticket->admissionRight()->isUsed());
        }
    }

    public function testIssueKeepsOrderEventAndBuyer(): void
    {
        $orderId = OrderId::generate();
        $eventId = EventId::generate();
        $email = EmailAddress::fromString('buyer@example.com');

        $bundle = TicketBundle::issue($orderId, $eventId, $email, [new OrderLine(PriceTierId::generate(), Quantity::of(1))], new FixedTicketTokenGenerator());

        self::assertSame($orderId, $bundle->orderId());
        self::assertSame($eventId, $bundle->eventId());
        self::assertSame($email, $bundle->buyerEmail());
    }

    public function testIssueRejectsEmptyLines(): void
    {
        $this->expectException(InvalidValueException::class);

        TicketBundle::issue(OrderId::generate(), EventId::generate(), EmailAddress::fromString('buyer@example.com'), [], new FixedTicketTokenGenerator());
    }

    public function testBundleIsNotDeliveredUntilMarked(): void
    {
        $bundle = self::bundle();

        self::assertFalse($bundle->isDelivered());
        self::assertNull($bundle->deliveredAt());

        $bundle->markDelivered(new \DateTimeImmutable('2026-12-31T20:30:00+01:00'));

        self::assertTrue($bundle->isDelivered());
        self::assertNotNull($bundle->deliveredAt());
        self::assertSame('2026-12-31T19:30:00+00:00', $bundle->deliveredAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $bundle->deliveredAt()->getTimezone()->getName());
    }

    public function testMarkingDeliveredTwiceKeepsTheFirstDate(): void
    {
        $bundle = self::bundle();

        $bundle->markDelivered(new \DateTimeImmutable('2026-12-31T10:00:00Z'));
        $bundle->markDelivered(new \DateTimeImmutable('2026-12-31T11:00:00Z'));

        self::assertNotNull($bundle->deliveredAt());
        self::assertSame('2026-12-31T10:00:00+00:00', $bundle->deliveredAt()->format(\DATE_ATOM));
    }

    public function testReconstituteRestoresAPersistedBundle(): void
    {
        $orderId = OrderId::generate();
        $eventId = EventId::generate();
        $email = EmailAddress::fromString('buyer@example.com');
        $ticket = new Ticket(TicketId::generate(), PriceTierId::generate(), new AdmissionRight(TicketToken::fromString(str_repeat('a', 43)), $eventId));

        $pending = TicketBundle::reconstitute($orderId, $eventId, $email, [$ticket], null);
        $delivered = TicketBundle::reconstitute($orderId, $eventId, $email, [$ticket], new \DateTimeImmutable('2026-12-31T20:30:00+01:00'));

        self::assertSame($orderId, $pending->orderId());
        self::assertSame([$ticket], $pending->tickets());
        self::assertFalse($pending->isDelivered());
        self::assertTrue($delivered->isDelivered());
        self::assertNotNull($delivered->deliveredAt());
        self::assertSame('UTC', $delivered->deliveredAt()->getTimezone()->getName());
    }

    private static function bundle(): TicketBundle
    {
        return TicketBundle::issue(
            OrderId::generate(),
            EventId::generate(),
            EmailAddress::fromString('buyer@example.com'),
            [new OrderLine(PriceTierId::generate(), Quantity::of(1))],
            new FixedTicketTokenGenerator(),
        );
    }
}
