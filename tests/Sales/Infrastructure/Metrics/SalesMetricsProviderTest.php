<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure\Metrics;

use App\Sales\Core\Domain\OrderStatus;
use App\Sales\Infrastructure\Metrics\SalesMetricsProvider;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Infrastructure\Observability\Metrics\Gauge;
use App\Tests\Support\Metrics\GaugeLookupTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SalesMetricsProviderTest extends KernelTestCase
{
    use GaugeLookupTrait;

    private Connection $connection;
    private SalesMetricsProvider $provider;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->provider = $container->get(SalesMetricsProvider::class);
    }

    public function testEveryOrderStatusIsExposed(): void
    {
        $gauges = [...$this->provider->collect()];

        foreach (OrderStatus::cases() as $status) {
            self::assertNotNull(self::gaugeValue($gauges, 'stubandgo_orders', ['status' => $status->value]), $status->value);
        }
    }

    public function testMetricsCountOrdersByStatus(): void
    {
        $before = [...$this->provider->collect()];
        $eventId = $this->seatStock(10, 3);
        $this->order($eventId, 'pending');
        $this->order($eventId, 'paid');
        $this->order($eventId, 'paid');

        $after = [...$this->provider->collect()];

        self::assertSame(1.0, $this->delta($before, $after, 'pending'));
        self::assertSame(2.0, $this->delta($before, $after, 'paid'));
        self::assertSame(0.0, $this->delta($before, $after, 'failed'));
        self::assertSame(0.0, $this->delta($before, $after, 'expired'));
    }

    public function testMetricsExposeSeatsTakenPerEvent(): void
    {
        $first = $this->seatStock(10, 3);
        $second = $this->seatStock(5, 0);

        $gauges = [...$this->provider->collect()];

        self::assertSame(3.0, self::gaugeValue($gauges, 'stubandgo_seats_taken', ['event_id' => $first]));
        self::assertSame(0.0, self::gaugeValue($gauges, 'stubandgo_seats_taken', ['event_id' => $second]));
    }

    public function testEventWithoutSeatStockIsNotExposed(): void
    {
        $gauges = [...$this->provider->collect()];

        self::assertNull(self::gaugeValue($gauges, 'stubandgo_seats_taken', ['event_id' => EventId::generate()->toString()]));
    }

    /**
     * @param iterable<Gauge> $before
     * @param iterable<Gauge> $after
     */
    private function delta(iterable $before, iterable $after, string $status): float
    {
        return (self::gaugeValue($after, 'stubandgo_orders', ['status' => $status]) ?? 0.0) - (self::gaugeValue($before, 'stubandgo_orders', ['status' => $status]) ?? 0.0);
    }

    private function seatStock(int $capacity, int $taken): string
    {
        $eventId = EventId::generate()->toString();
        $this->connection->executeStatement('INSERT INTO seat_stock (event_id, capacity, taken) VALUES (?, ?, ?)', [$eventId, $capacity, $taken]);

        return $eventId;
    }

    private function order(string $eventId, string $status): void
    {
        $this->connection->executeStatement(
            "INSERT INTO sales_order (id, event_id, buyer_email, event_starts_at, total_cents, commission_cents, net_cents, status, expires_at) VALUES (?, ?, 'b@example.com', now(), 100, 10, 90, ?, now())",
            [OrderId::generate()->toString(), $eventId, $status],
        );
    }
}
