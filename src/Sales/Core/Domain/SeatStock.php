<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Shared\ValueObject\Quantity;

/**
 * Transition rules of the seat counter of an event. The counter itself lives
 * in the repository, which updates it atomically.
 */
final readonly class SeatStock
{
    /**
     * True when the reservation took the first seats of the event: nothing was taken before it.
     */
    public static function reservationTookFirstSeats(int $takenAfter, Quantity $reserved): bool
    {
        return $takenAfter === $reserved->toInt();
    }

    /**
     * True when the release freed the last taken seats of the event.
     */
    public static function releaseFreedAllSeats(int $takenAfter): bool
    {
        return 0 === $takenAfter;
    }
}
