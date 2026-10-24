<?php

declare(strict_types=1);

namespace App\Tests\Shared\Event;

use App\Shared\Event\OrderPaid;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class OrderPaidTest extends TestCase
{
    public function testOrderPaidExposesLines(): void
    {
        $orderId = OrderId::generate();
        $eventId = EventId::generate();
        $email = EmailAddress::fromString('ana@example.com');
        $lines = [
            new OrderLine(PriceTierId::generate(), Quantity::of(2)),
            new OrderLine(PriceTierId::generate(), Quantity::of(1)),
        ];

        $event = new OrderPaid($orderId, $eventId, $email, $lines);

        self::assertSame($orderId, $event->orderId);
        self::assertSame($eventId, $event->eventId);
        self::assertSame($email, $event->buyerEmail);
        self::assertSame($lines, $event->lines);
        self::assertSame(2, $event->lines[0]->quantity->toInt());
    }
}
