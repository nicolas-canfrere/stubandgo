<?php

declare(strict_types=1);

namespace App\Tests\Sales\Concurrency;

use App\Shared\Identity\OrderId;
use App\Tests\Support\ParallelRunner;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
final class SeatReservationConcurrencyTest extends AbstractSalesConcurrencyTestCase
{
    private const int PROCESSES = 20;

    public function testConcurrentReservationsNeverExceedCapacity(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 5);
        $commands = [];
        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $commands[] = ['test:sales:dispatch', 'place-order', OrderId::generate()->toString(), $eventId, $tier, '1'];
        }

        $outcomes = array_count_values(ParallelRunner::run($commands));
        ksort($outcomes);

        self::assertSame(['SoldOutException' => 15, 'ok' => 5], $outcomes);
        self::assertSame(5, $this->taken($eventId));
        self::assertSame(5, $this->connection()->fetchOne('SELECT COUNT(*) FROM sales_order WHERE event_id = ?', [$eventId]));
    }
}
