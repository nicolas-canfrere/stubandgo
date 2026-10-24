<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\AdmissionRight;
use App\Access\Core\Domain\Exception\TicketBundleNotFoundException;
use App\Access\Core\Domain\Ticket;
use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Core\Domain\ValueObject\TicketId;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-type BundleRow array{order_id: string, event_id: string, buyer_email: string, delivered_at: string|null}
 * @phpstan-type TicketRow array{id: string, price_tier_id: string, token: string, event_id: string, used_at: string|null}
 */
final readonly class DbalTicketBundleRepository implements TicketBundleRepositoryInterface
{
    private const string SELECT_BUNDLE = 'SELECT order_id, event_id, buyer_email, delivered_at FROM ticket_bundle WHERE order_id = :id';
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function save(TicketBundle $bundle): void
    {
        $orderId = $bundle->orderId()->toString();
        $this->connection->executeStatement(
            'INSERT INTO ticket_bundle (order_id, event_id, buyer_email, delivered_at)
             VALUES (:order_id, :event_id, :buyer_email, :delivered_at)
             ON CONFLICT (order_id) DO UPDATE SET delivered_at = EXCLUDED.delivered_at',
            [
                'order_id' => $orderId,
                'event_id' => $bundle->eventId()->toString(),
                'buyer_email' => $bundle->buyerEmail()->toString(),
                'delivered_at' => $bundle->deliveredAt()?->format(self::TIMESTAMP_FORMAT),
            ],
        );

        foreach ($bundle->tickets() as $ticket) {
            $this->connection->executeStatement(
                'INSERT INTO ticket (id, order_id, price_tier_id) VALUES (:id, :order_id, :price_tier_id) ON CONFLICT (id) DO NOTHING',
                ['id' => $ticket->id()->toString(), 'order_id' => $orderId, 'price_tier_id' => $ticket->priceTierId()->toString()],
            );
            $this->connection->executeStatement(
                'INSERT INTO admission_right (ticket_id, token, event_id) VALUES (:ticket_id, :token, :event_id) ON CONFLICT (ticket_id) DO NOTHING',
                ['ticket_id' => $ticket->id()->toString(), 'token' => $ticket->admissionRight()->token()->toString(), 'event_id' => $ticket->admissionRight()->eventId()->toString()],
            );
        }
    }

    public function get(OrderId $orderId): TicketBundle
    {
        return $this->load($orderId, self::SELECT_BUNDLE);
    }

    public function getForUpdate(OrderId $orderId): TicketBundle
    {
        return $this->load($orderId, self::SELECT_BUNDLE . ' FOR UPDATE');
    }

    private function load(OrderId $orderId, string $sql): TicketBundle
    {
        /** @var BundleRow|false $row */
        $row = $this->connection->fetchAssociative($sql, ['id' => $orderId->toString()]);

        if (false === $row) {
            throw TicketBundleNotFoundException::forOrder($orderId);
        }

        return TicketBundle::reconstitute(
            $orderId,
            EventId::fromString($row['event_id']),
            EmailAddress::fromString($row['buyer_email']),
            $this->loadTickets($orderId),
            null === $row['delivered_at'] ? null : new \DateTimeImmutable($row['delivered_at']),
        );
    }

    /**
     * @return list<Ticket>
     */
    private function loadTickets(OrderId $orderId): array
    {
        /** @var list<TicketRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.price_tier_id, a.token, a.event_id, a.used_at
             FROM ticket t JOIN admission_right a ON a.ticket_id = t.id
             WHERE t.order_id = :id ORDER BY t.id',
            ['id' => $orderId->toString()],
        );

        return array_map(
            static fn(array $row): Ticket => new Ticket(
                TicketId::fromString($row['id']),
                PriceTierId::fromString($row['price_tier_id']),
                new AdmissionRight(
                    TicketToken::fromString($row['token']),
                    EventId::fromString($row['event_id']),
                    null === $row['used_at'] ? null : new \DateTimeImmutable($row['used_at']),
                ),
            ),
            $rows,
        );
    }
}
