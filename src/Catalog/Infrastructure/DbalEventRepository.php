<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\PriceTier;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\Capacity;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Money\Money;

/**
 * @phpstan-type EventRow array{id: string, organization_id: string, title: string, description: string, venue: string, starts_at: string, capacity: int, status: string, locked: bool}
 * @phpstan-type TierRow array{id: string, event_id: string, label: string, price_cents: int}
 */
final readonly class DbalEventRepository implements EventRepositoryInterface
{
    private const string EVENT_COLUMNS = 'id, organization_id, title, description, venue, starts_at, capacity, status, locked';
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function save(Event $event): void
    {
        $tierIds = array_map(static fn(PriceTier $tier): string => $tier->id()->toString(), $event->priceTiers());

        // The organization and the identifier never change: updating no key column only takes a FOR NO KEY UPDATE
        // lock, which the FOR KEY SHARE lock of a concurrent order does not block.
        $this->connection->executeStatement(
            'INSERT INTO event (id, organization_id, title, description, venue, starts_at, capacity, status, locked)
             VALUES (:id, :organization_id, :title, :description, :venue, :starts_at, :capacity, :status, :locked)
             ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title, description = EXCLUDED.description, venue = EXCLUDED.venue,
                 starts_at = EXCLUDED.starts_at, capacity = EXCLUDED.capacity, status = EXCLUDED.status, locked = EXCLUDED.locked',
            [
                'id' => $event->id()->toString(),
                'organization_id' => $event->organizationId()->toString(),
                'title' => $event->title()->toString(),
                'description' => $event->description()->toString(),
                'venue' => $event->venue()->toString(),
                'starts_at' => $event->startsAt()->format(self::TIMESTAMP_FORMAT),
                'capacity' => $event->capacity()->toInt(),
                'status' => $event->status()->value,
                'locked' => $event->isLocked(),
            ],
            ['locked' => ParameterType::BOOLEAN],
        );

        foreach ($event->priceTiers() as $position => $tier) {
            // A tier of another event is neither inserted nor updated by the guard of the upsert: no row is written.
            $written = $this->connection->executeStatement(
                'INSERT INTO price_tier (id, event_id, position, label, price_cents)
                 VALUES (:id, :event_id, :position, :label, :price_cents)
                 ON CONFLICT (id) DO UPDATE SET position = EXCLUDED.position, label = EXCLUDED.label, price_cents = EXCLUDED.price_cents
                 WHERE price_tier.event_id = EXCLUDED.event_id',
                [
                    'id' => $tier->id()->toString(),
                    'event_id' => $event->id()->toString(),
                    'position' => $position,
                    'label' => $tier->label()->toString(),
                    'price_cents' => $tier->price()->getAmount(),
                ],
            );

            if (0 === $written) {
                throw new InvalidValueException('A price tier belongs to another event.');
            }
        }

        // Last: the tiers just written are among those the deletion must spare, so a kept tier is updated in place.
        $this->deleteTiersOtherThan($event->id(), $tierIds);
    }

    public function get(EventId $id): Event
    {
        return $this->find($id) ?? throw EventNotFoundException::withId($id);
    }

    public function getForUpdate(EventId $id): Event
    {
        return $this->load($id, ' FOR UPDATE') ?? throw EventNotFoundException::withId($id);
    }

    public function find(EventId $id): ?Event
    {
        return $this->load($id, '');
    }

    public function findForSale(EventId $id): ?Event
    {
        return $this->load($id, ' FOR KEY SHARE');
    }

    public function listPublishedStartingAfter(\DateTimeImmutable $now): array
    {
        /** @var list<EventRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::EVENT_COLUMNS . " FROM event WHERE status = 'published' AND starts_at > :now ORDER BY starts_at, id",
            ['now' => $now->format(self::TIMESTAMP_FORMAT)],
        );

        return $this->hydrate($rows);
    }

    public function listPublishedByOrganization(OrganizationId $organizationId, \DateTimeImmutable $now): array
    {
        /** @var list<EventRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::EVENT_COLUMNS . " FROM event WHERE status = 'published' AND organization_id = :organization_id AND starts_at > :now ORDER BY starts_at, id",
            ['organization_id' => $organizationId->toString(), 'now' => $now->format(self::TIMESTAMP_FORMAT)],
        );

        return $this->hydrate($rows);
    }

    public function listByOrganization(OrganizationId $organizationId): array
    {
        /** @var list<EventRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::EVENT_COLUMNS . ' FROM event WHERE organization_id = :organization_id ORDER BY starts_at, id',
            ['organization_id' => $organizationId->toString()],
        );

        return $this->hydrate($rows);
    }

    private function load(EventId $id, string $lock): ?Event
    {
        /** @var EventRow|false $row */
        $row = $this->connection->fetchAssociative('SELECT ' . self::EVENT_COLUMNS . ' FROM event WHERE id = :id' . $lock, ['id' => $id->toString()]);

        return false === $row ? null : $this->hydrate([$row])[0];
    }

    /**
     * The identifiers go as one PostgreSQL array, so that an empty list deletes every tier of the event (a NOT IN of
     * an empty list would expand to NOT IN (NULL) and delete none).
     *
     * @param list<string> $tierIds
     */
    private function deleteTiersOtherThan(EventId $eventId, array $tierIds): void
    {
        $this->connection->executeStatement(
            'DELETE FROM price_tier WHERE event_id = :event_id AND NOT (id = ANY (CAST(:ids AS text[])))',
            ['event_id' => $eventId->toString(), 'ids' => '{' . implode(',', $tierIds) . '}'],
        );
    }

    /**
     * @param list<EventRow> $rows
     *
     * @return list<Event>
     */
    private function hydrate(array $rows): array
    {
        $tiers = $this->loadTiers(array_column($rows, 'id'));

        return array_map(
            static fn(array $row): Event => Event::reconstitute(
                EventId::fromString($row['id']),
                OrganizationId::fromString($row['organization_id']),
                EventTitle::fromString($row['title']),
                EventDescription::fromString($row['description']),
                Venue::fromString($row['venue']),
                new \DateTimeImmutable($row['starts_at']),
                Capacity::of($row['capacity']),
                $tiers[$row['id']] ?? [],
                EventStatus::from($row['status']),
                $row['locked'],
            ),
            $rows,
        );
    }

    /**
     * @param list<string> $eventIds
     *
     * @return array<string, list<PriceTier>> price tiers by event identifier, in declared order
     */
    private function loadTiers(array $eventIds): array
    {
        /** @var list<TierRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, event_id, label, price_cents FROM price_tier WHERE event_id IN (:ids) ORDER BY event_id, position',
            ['ids' => $eventIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $tiers = [];
        foreach ($rows as $row) {
            $tiers[$row['event_id']][] = new PriceTier(
                PriceTierId::fromString($row['id']),
                PriceTierLabel::fromString($row['label']),
                Money::EUR($row['price_cents']),
            );
        }

        return $tiers;
    }
}
