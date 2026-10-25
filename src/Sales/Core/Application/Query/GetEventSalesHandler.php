<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Sales\Core\Domain\EventOffer;
use App\Sales\Core\Domain\EventOfferGatewayInterface;
use App\Sales\Core\Domain\EventSalesFigures;
use App\Sales\Core\Domain\EventSalesReadModelInterface;
use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Shared\Identity\EventId;

final readonly class GetEventSalesHandler
{
    public function __construct(
        private EventSalesReadModelInterface $sales,
        private EventOfferGatewayInterface $offers,
    ) {
    }

    public function __invoke(GetEventSales $query): EventSalesView
    {
        $eventId = EventId::fromString($query->eventId);
        $offer = $this->offers->forEvent($eventId) ?? throw EventNotOnSaleException::withId($eventId);
        $figures = $this->sales->forEvent($eventId);

        $priceTiers = [];
        foreach ($offer->priceTierLabels as $priceTierId => $label) {
            $sold = $figures->byPriceTier[$priceTierId] ?? ['ticketsSold' => 0, 'totalCents' => 0];
            $priceTiers[] = new PriceTierSalesView((string) $priceTierId, $label, $sold['ticketsSold'], $sold['totalCents']);
        }

        return new EventSalesView(
            $eventId->toString(),
            self::seatsRemaining($offer, $figures),
            $figures->ticketsSold,
            $figures->totalCents,
            $figures->commissionCents,
            $figures->netCents,
            $priceTiers,
        );
    }

    /**
     * On sale, the seat stock decides. Off sale, the stock keeps the capacity of the last publication, which a revision
     * may have changed since: the capacity of the catalog counts, minus the seats still taken.
     */
    private static function seatsRemaining(EventOffer $offer, EventSalesFigures $figures): int
    {
        return $offer->onSale ? $figures->seatsRemaining ?? $offer->capacity : $offer->capacity - $figures->seatsTaken;
    }
}
