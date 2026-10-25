<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\SeatStockRepositoryInterface;
use App\Shared\Identity\EventId;
use App\Shared\ValueObject\Capacity;
use App\Shared\ValueObject\Quantity;
use Doctrine\DBAL\Connection;

final readonly class DbalSeatStockRepository implements SeatStockRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(EventId $eventId, Capacity $capacity): void
    {
        $this->connection->executeStatement(
            'INSERT INTO seat_stock (event_id, capacity, taken) VALUES (:id, :capacity, 0)
             ON CONFLICT (event_id) DO UPDATE SET capacity = EXCLUDED.capacity',
            ['id' => $eventId->toString(), 'capacity' => $capacity->toInt()],
        );
    }

    public function reserve(EventId $eventId, Quantity $quantity): ?int
    {
        $taken = $this->connection->fetchOne(
            'UPDATE seat_stock SET taken = taken + :n WHERE event_id = :id AND taken + :n <= capacity RETURNING taken',
            ['id' => $eventId->toString(), 'n' => $quantity->toInt()],
        );

        return \is_int($taken) ? $taken : null;
    }

    public function release(EventId $eventId, Quantity $quantity): int
    {
        $taken = $this->connection->fetchOne(
            'UPDATE seat_stock SET taken = taken - :n WHERE event_id = :id RETURNING taken',
            ['id' => $eventId->toString(), 'n' => $quantity->toInt()],
        );

        return \is_int($taken) ? $taken : throw new \LogicException(\sprintf('Event "%s" has no seat stock.', $eventId->toString()));
    }

    public function available(EventId $eventId): int
    {
        $available = $this->connection->fetchOne('SELECT capacity - taken FROM seat_stock WHERE event_id = :id', ['id' => $eventId->toString()]);

        return \is_int($available) ? $available : throw EventNotOnSaleException::withId($eventId);
    }
}
