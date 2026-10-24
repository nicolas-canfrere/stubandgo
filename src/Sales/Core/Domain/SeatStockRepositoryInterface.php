<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Shared\Identity\EventId;
use App\Shared\ValueObject\Capacity;
use App\Shared\ValueObject\Quantity;

interface SeatStockRepositoryInterface
{
    public function create(EventId $eventId, Capacity $capacity): void;

    /**
     * Atomically takes seats.
     *
     * @return int|null the number of seats taken after the reservation, null when there are not enough seats left
     */
    public function reserve(EventId $eventId, Quantity $quantity): ?int;

    /**
     * Atomically gives seats back.
     *
     * @return int the number of seats taken after the release
     */
    public function release(EventId $eventId, Quantity $quantity): int;

    /**
     * @return int the number of seats left
     *
     * @throws EventNotOnSaleException when the event has no seat stock
     */
    public function available(EventId $eventId): int;
}
