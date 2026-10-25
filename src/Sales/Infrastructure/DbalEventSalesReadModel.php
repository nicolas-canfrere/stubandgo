<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Sales\Core\Domain\EventSalesFigures;
use App\Sales\Core\Domain\EventSalesReadModelInterface;
use App\Shared\Identity\EventId;
use Doctrine\DBAL\Connection;

final readonly class DbalEventSalesReadModel implements EventSalesReadModelInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forEvent(EventId $eventId): EventSalesFigures
    {
        $id = $eventId->toString();
        [$total, $commission, $net] = $this->paidAmounts($id);
        $byPriceTier = $this->paidByPriceTier($id);
        [$remaining, $taken] = $this->seats($id);

        return new EventSalesFigures(
            array_sum(array_column($byPriceTier, 'ticketsSold')),
            $total,
            $commission,
            $net,
            $byPriceTier,
            $remaining,
            $taken,
        );
    }

    /**
     * @return array{int, int, int} total, commission and net of the paid orders, frozen when they were placed
     */
    private function paidAmounts(string $eventId): array
    {
        $amounts = $this->connection->fetchNumeric(
            "SELECT CAST(COALESCE(SUM(total_cents), 0) AS BIGINT), CAST(COALESCE(SUM(commission_cents), 0) AS BIGINT), CAST(COALESCE(SUM(net_cents), 0) AS BIGINT)
             FROM sales_order WHERE event_id = :id AND status = 'paid'",
            ['id' => $eventId],
        );
        \assert(\is_array($amounts));
        [$total, $commission, $net] = $amounts;
        \assert(\is_int($total));
        \assert(\is_int($commission));
        \assert(\is_int($net));

        return [$total, $commission, $net];
    }

    /**
     * @return array<string, array{ticketsSold: int, totalCents: int}>
     */
    private function paidByPriceTier(string $eventId): array
    {
        $byPriceTier = [];
        $rows = $this->connection->fetchAllNumeric(
            "SELECT l.price_tier_id, CAST(SUM(l.quantity) AS BIGINT), CAST(SUM(l.quantity * l.unit_price_cents) AS BIGINT)
             FROM sales_order_line l JOIN sales_order o ON o.id = l.order_id
             WHERE o.event_id = :id AND o.status = 'paid'
             GROUP BY l.price_tier_id",
            ['id' => $eventId],
        );
        foreach ($rows as [$priceTierId, $tickets, $total]) {
            \assert(\is_string($priceTierId));
            \assert(\is_int($tickets));
            \assert(\is_int($total));
            $byPriceTier[$priceTierId] = ['ticketsSold' => $tickets, 'totalCents' => $total];
        }

        return $byPriceTier;
    }

    /**
     * @return array{int|null, int} seats remaining (null without seat stock) and seats taken (0 without seat stock)
     */
    private function seats(string $eventId): array
    {
        $seats = $this->connection->fetchNumeric('SELECT capacity - taken, taken FROM seat_stock WHERE event_id = :id', ['id' => $eventId]);

        if (false === $seats) {
            return [null, 0];
        }

        [$remaining, $taken] = $seats;
        \assert(\is_int($remaining) && \is_int($taken));

        return [$remaining, $taken];
    }
}
