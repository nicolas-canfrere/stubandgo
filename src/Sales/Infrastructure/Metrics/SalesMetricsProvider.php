<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Metrics;

use App\Sales\Core\Domain\OrderStatus;
use App\Shared\Infrastructure\Observability\Metrics\Gauge;
use App\Shared\Infrastructure\Observability\Metrics\MetricsProviderInterface;
use Doctrine\DBAL\Connection;

final readonly class SalesMetricsProvider implements MetricsProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function collect(): iterable
    {
        /** @var array<string, int|string> $counts */
        $counts = $this->connection->fetchAllKeyValue('SELECT status, COUNT(*) FROM sales_order GROUP BY status');
        foreach (OrderStatus::cases() as $status) {
            yield new Gauge('stubandgo_orders', 'Orders by status.', (int) ($counts[$status->value] ?? 0), ['status' => $status->value]);
        }

        /** @var list<array{event_id: string, taken: int}> $seats */
        $seats = $this->connection->fetchAllAssociative('SELECT event_id, taken FROM seat_stock ORDER BY event_id');
        foreach ($seats as $seat) {
            yield new Gauge('stubandgo_seats_taken', 'Seats held by pending or paid orders, per event.', $seat['taken'], ['event_id' => $seat['event_id']]);
        }
    }
}
