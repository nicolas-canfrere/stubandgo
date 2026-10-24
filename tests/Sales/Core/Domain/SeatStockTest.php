<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain;

use App\Sales\Core\Domain\SeatStock;
use App\Shared\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class SeatStockTest extends TestCase
{
    public function testReservationTookFirstSeatsWhenTakenEqualsReserved(): void
    {
        self::assertTrue(SeatStock::reservationTookFirstSeats(3, Quantity::of(3)));
    }

    public function testReservationDidNotTakeFirstSeatsWhenOthersAlreadyHeldSome(): void
    {
        self::assertFalse(SeatStock::reservationTookFirstSeats(4, Quantity::of(3)));
    }

    public function testReleaseFreedAllSeatsWhenNoneRemainTaken(): void
    {
        self::assertTrue(SeatStock::releaseFreedAllSeats(0));
    }

    public function testReleaseDidNotFreeAllSeatsWhenSomeRemainTaken(): void
    {
        self::assertFalse(SeatStock::releaseFreedAllSeats(1));
    }
}
