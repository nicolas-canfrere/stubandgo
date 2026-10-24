<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

use App\Sales\Core\Domain\Order;
use App\Sales\Core\Domain\SeatStock;
use App\Sales\Core\Domain\SeatStockRepositoryInterface;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Event\EventSeatsAllReleased;

/**
 * Gives the seats of an order that ended unpaid back to the stock, and announces when the event has no seat taken
 * anymore.
 */
final readonly class OrderSeatsReleaser
{
    public function __construct(
        private SeatStockRepositoryInterface $seatStocks,
        private EventBusInterface $eventBus,
    ) {
    }

    public function release(Order $order): void
    {
        $taken = $this->seatStocks->release($order->eventId(), $order->totalQuantity());

        if (SeatStock::releaseFreedAllSeats($taken)) {
            $this->eventBus->publish(new EventSeatsAllReleased($order->eventId()));
        }
    }
}
