<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure;

use App\Sales\Core\Application\Command\PlaceOrder;
use App\Sales\Core\Domain\EventSalesReadModelInterface;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\AbstractCatalogTestCase;

final class DbalEventSalesReadModelTest extends AbstractCatalogTestCase
{
    public function testTheSeatStockGivesTheSeatsTakenAndRemaining(): void
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]);
        $this->command(new PlaceOrder(OrderId::generate()->toString(), $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 2]]));

        $figures = $this->readModel()->forEvent(EventId::fromString($eventId));

        self::assertSame(2, $figures->seatsTaken);
        self::assertSame(98, $figures->seatsRemaining);
    }

    public function testAnEventWithoutSeatStockHasNoSeatTaken(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00');

        $figures = $this->readModel()->forEvent(EventId::fromString($eventId));

        self::assertSame(0, $figures->seatsTaken);
        self::assertNull($figures->seatsRemaining);
    }

    private function readModel(): EventSalesReadModelInterface
    {
        return self::getContainer()->get(EventSalesReadModelInterface::class);
    }
}
