<?php

declare(strict_types=1);

namespace App\Tests\Access\Concurrency;

use App\Tests\Access\AdmissionRightFixturesTrait;
use App\Tests\Support\ParallelRunner;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The parallel processes only see committed data: this test bypasses the rollback of DAMA, commits its admission
 * right (seeded through the bundle repository, so without any outbox message) and deletes everything it created.
 */
#[Group('concurrency')]
#[SkipDatabaseRollback]
final class DoubleScanConcurrencyTest extends KernelTestCase
{
    use AdmissionRightFixturesTrait;

    private const int PROCESSES = 10;

    /** @var array{orderId: string, eventId: string, token: string}|null */
    private ?array $right = null;

    protected function tearDown(): void
    {
        if (null !== $this->right) {
            $connection = $this->connection();
            $connection->executeStatement('DELETE FROM admission_right WHERE ticket_id IN (SELECT id FROM ticket WHERE order_id = ?)', [$this->right['orderId']]);
            $connection->executeStatement('DELETE FROM ticket WHERE order_id = ?', [$this->right['orderId']]);
            $connection->executeStatement('DELETE FROM ticket_bundle WHERE order_id = ?', [$this->right['orderId']]);
            $connection->executeStatement('DELETE FROM scan_attempt WHERE event_id = ?', [$this->right['eventId']]);
            $this->right = null;
        }

        parent::tearDown();
    }

    public function testConcurrentScansYieldExactlyOneValid(): void
    {
        $this->right = $this->issueAdmissionRight();
        $commands = array_fill(0, self::PROCESSES, ['test:access:scan', $this->right['eventId'], $this->right['token']]);

        $outcomes = array_count_values(ParallelRunner::run($commands));
        ksort($outcomes);

        self::assertSame(['already_used' => 9, 'valid' => 1], $outcomes);
        $recorded = $this->connection()->fetchAllKeyValue('SELECT outcome, COUNT(*) FROM scan_attempt WHERE event_id = ? GROUP BY outcome ORDER BY outcome', [$this->right['eventId']]);
        self::assertSame(['already_used' => 9, 'valid' => 1], $recorded);
        // Every refused scan reports the date of the single valid one.
        self::assertSame(9, $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM scan_attempt a JOIN scan_attempt v ON v.event_id = a.event_id AND v.outcome = 'valid'
             WHERE a.event_id = ? AND a.outcome = 'already_used' AND a.first_used_at = v.scanned_at",
            [$this->right['eventId']],
        ));
        self::assertNotNull($this->connection()->fetchOne('SELECT used_at FROM admission_right WHERE token = ?', [$this->right['token']]));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
