<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Command;

use App\Catalog\Core\Application\Command\PublishEvent;
use App\Catalog\Core\Application\Command\ReviseEvent;
use App\Catalog\Core\Application\Command\UnpublishEvent;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\Exception\EventLockedException;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\Exception\EventNotPublishedException;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\AbstractCatalogTestCase;
use Doctrine\DBAL\Connection;

final class UnpublishEventHandlerTest extends AbstractCatalogTestCase
{
    public function testUnpublishTurnsThePublishedEventBackIntoADraftOffSale(): void
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]);

        $this->command(new UnpublishEvent($eventId));

        self::assertSame(EventStatus::Draft, self::getContainer()->get(EventRepositoryInterface::class)->get(EventId::fromString($eventId))->status());
        $this->expectException(EventNotOnSaleException::class);
        $this->command(new PlaceOrder(OrderId::generate()->toString(), $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 1]]));
    }

    public function testUnpublishRefusesADraft(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00');

        $this->expectException(EventNotPublishedException::class);

        $this->command(new UnpublishEvent($eventId));
    }

    public function testUnpublishRefusesALockedEvent(): void
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]);
        $this->command(new PlaceOrder(OrderId::generate()->toString(), $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 1]]));

        $this->expectException(EventLockedException::class);

        $this->command(new UnpublishEvent($eventId));
    }

    public function testUnpublishUnknownEventFails(): void
    {
        $this->expectException(EventNotFoundException::class);

        $this->command(new UnpublishEvent(EventId::generate()->toString()));
    }

    public function testRepublishingWithAnotherCapacityReplacesTheSeatStockCapacity(): void
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]], capacity: 100);
        $this->command(new UnpublishEvent($eventId));
        $this->command(new ReviseEvent($eventId, 'Concert', 'Un super concert', 'La Cigale', '2026-12-01T20:00:00+00:00', 7, [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]));

        $this->command(new PublishEvent($eventId));

        self::assertSame([['capacity' => 7, 'taken' => 0]], self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT capacity, taken FROM seat_stock WHERE event_id = ?', [$eventId]));
    }

    public function testUnpublishCommandCarriesItsEventId(): void
    {
        self::assertSame('a', new UnpublishEvent('a')->eventId);
    }
}
