<?php

declare(strict_types=1);

namespace App\Tests\Sales\Concurrency;

use App\Tests\Sales\AbstractSalesTestCase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The parallel processes only see committed data: these tests bypass the rollback of DAMA, commit their
 * fixtures and delete everything they created afterwards.
 */
#[SkipDatabaseRollback]
abstract class AbstractSalesConcurrencyTestCase extends AbstractSalesTestCase
{
    protected const string FAR_FUTURE = '2099-01-01T20:00:00+00:00';

    /** @var list<string> */
    private array $eventIds = [];

    protected function tearDown(): void
    {
        $this->deleteCommittedData();
        parent::tearDown();
    }

    protected function eventOnSale(int $capacity = 100, array $pricesCents = [2500], int $commissionBasisPoints = 1000, string $startsAt = self::FAR_FUTURE): array
    {
        $event = parent::eventOnSale($capacity, $pricesCents, $commissionBasisPoints, $startsAt);
        $this->eventIds[] = $event['eventId'];

        return $event;
    }

    protected function taken(string $eventId): mixed
    {
        return $this->connection()->fetchOne('SELECT taken FROM seat_stock WHERE event_id = ?', [$eventId]);
    }

    protected function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function deleteCommittedData(): void
    {
        if ([] === $this->eventIds) {
            return;
        }

        $connection = $this->connection();
        $parameters = ['events' => $this->eventIds];
        $types = ['events' => ArrayParameterType::STRING];
        $organizationIds = $connection->fetchFirstColumn('SELECT DISTINCT organization_id FROM event WHERE id IN (:events)', $parameters, $types);

        $connection->executeStatement('DELETE FROM sales_order_line WHERE order_id IN (SELECT id FROM sales_order WHERE event_id IN (:events))', $parameters, $types);
        $connection->executeStatement('DELETE FROM sales_order WHERE event_id IN (:events)', $parameters, $types);
        $connection->executeStatement('DELETE FROM seat_stock WHERE event_id IN (:events)', $parameters, $types);
        $connection->executeStatement('DELETE FROM price_tier WHERE event_id IN (:events)', $parameters, $types);
        $connection->executeStatement('DELETE FROM event WHERE id IN (:events)', $parameters, $types);
        $connection->executeStatement('DELETE FROM organization WHERE id IN (:organizations)', ['organizations' => $organizationIds], ['organizations' => ArrayParameterType::STRING]);
        $this->eventIds = [];
    }
}
