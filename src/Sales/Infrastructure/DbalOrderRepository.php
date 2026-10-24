<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Sales\Core\Domain\Exception\OrderNotFoundException;
use App\Sales\Core\Domain\Order;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Sales\Core\Domain\OrderStatus;
use App\Sales\Core\Domain\ValueObject\OrderAmounts;
use App\Sales\Core\Domain\ValueObject\PricedOrderLine;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use Doctrine\DBAL\Connection;
use Money\Money;

/**
 * @phpstan-type OrderRow array{id: string, event_id: string, buyer_email: string, event_starts_at: string, total_cents: int, commission_cents: int, net_cents: int, status: string, expires_at: string}
 * @phpstan-type LineRow array{price_tier_id: string, quantity: int, unit_price_cents: int}
 */
final readonly class DbalOrderRepository implements OrderRepositoryInterface
{
    private const string SELECT_ORDER = 'SELECT id, event_id, buyer_email, event_starts_at, total_cents, commission_cents, net_cents, status, expires_at FROM sales_order WHERE id = :id';
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function save(Order $order): void
    {
        $this->connection->executeStatement(
            'INSERT INTO sales_order (id, event_id, buyer_email, event_starts_at, total_cents, commission_cents, net_cents, status, expires_at)
             VALUES (:id, :event_id, :buyer_email, :event_starts_at, :total_cents, :commission_cents, :net_cents, :status, :expires_at)
             ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status',
            [
                'id' => $order->id()->toString(),
                'event_id' => $order->eventId()->toString(),
                'buyer_email' => $order->buyerEmail()->toString(),
                'event_starts_at' => $order->eventStartsAt()->format(self::TIMESTAMP_FORMAT),
                'total_cents' => $order->amounts()->total()->getAmount(),
                'commission_cents' => $order->amounts()->commission()->getAmount(),
                'net_cents' => $order->amounts()->net()->getAmount(),
                'status' => $order->status()->value,
                'expires_at' => $order->expiresAt()->format(self::TIMESTAMP_FORMAT),
            ],
        );

        foreach ($order->lines() as $position => $line) {
            $this->connection->executeStatement(
                'INSERT INTO sales_order_line (order_id, position, price_tier_id, quantity, unit_price_cents)
                 VALUES (:order_id, :position, :price_tier_id, :quantity, :unit_price_cents)
                 ON CONFLICT (order_id, position) DO NOTHING',
                [
                    'order_id' => $order->id()->toString(),
                    'position' => $position,
                    'price_tier_id' => $line->line()->priceTierId->toString(),
                    'quantity' => $line->line()->quantity->toInt(),
                    'unit_price_cents' => $line->unitPrice()->getAmount(),
                ],
            );
        }
    }

    public function get(OrderId $id): Order
    {
        return $this->load($id, self::SELECT_ORDER);
    }

    public function getForUpdate(OrderId $id): Order
    {
        return $this->load($id, self::SELECT_ORDER . ' FOR UPDATE');
    }

    public function overduePendingIds(\DateTimeImmutable $now): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM sales_order WHERE status = 'pending' AND expires_at <= :now ORDER BY expires_at, id",
            ['now' => $now->format(self::TIMESTAMP_FORMAT)],
        );

        return array_map(OrderId::fromString(...), $ids);
    }

    private function load(OrderId $id, string $sql): Order
    {
        /** @var OrderRow|false $row */
        $row = $this->connection->fetchAssociative($sql, ['id' => $id->toString()]);

        if (false === $row) {
            throw OrderNotFoundException::withId($id);
        }

        return Order::reconstitute(
            OrderId::fromString($row['id']),
            EventId::fromString($row['event_id']),
            EmailAddress::fromString($row['buyer_email']),
            $this->loadLines($id),
            new \DateTimeImmutable($row['event_starts_at']),
            OrderAmounts::of(Money::EUR($row['total_cents']), Money::EUR($row['commission_cents']), Money::EUR($row['net_cents'])),
            OrderStatus::from($row['status']),
            new \DateTimeImmutable($row['expires_at']),
        );
    }

    /**
     * @return list<PricedOrderLine>
     */
    private function loadLines(OrderId $id): array
    {
        /** @var list<LineRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT price_tier_id, quantity, unit_price_cents FROM sales_order_line WHERE order_id = :id ORDER BY position',
            ['id' => $id->toString()],
        );

        return array_map(
            static fn(array $row): PricedOrderLine => new PricedOrderLine(
                new OrderLine(PriceTierId::fromString($row['price_tier_id']), Quantity::of($row['quantity'])),
                Money::EUR($row['unit_price_cents']),
            ),
            $rows,
        );
    }
}
