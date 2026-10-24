<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Sales\Core\Domain\SeatStockRepositoryInterface;
use App\Shared\Identity\EventId;

final readonly class GetEventAvailabilityHandler
{
    public function __construct(private SeatStockRepositoryInterface $seatStocks)
    {
    }

    public function __invoke(GetEventAvailability $query): EventAvailabilityView
    {
        $eventId = EventId::fromString($query->eventId);

        return new EventAvailabilityView($eventId->toString(), $this->seatStocks->available($eventId));
    }
}
