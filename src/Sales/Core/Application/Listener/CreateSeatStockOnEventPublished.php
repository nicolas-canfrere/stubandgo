<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Listener;

use App\Sales\Core\Domain\SeatStockRepositoryInterface;
use App\Shared\Event\EventPublished;

final readonly class CreateSeatStockOnEventPublished
{
    public function __construct(private SeatStockRepositoryInterface $seatStocks)
    {
    }

    public function __invoke(EventPublished $event): void
    {
        $this->seatStocks->create($event->eventId, $event->capacity);
    }
}
