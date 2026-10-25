<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\WorkerHeartbeat;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class WorkerHeartbeatTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private Connection $connection;
    private WorkerHeartbeat $heartbeat;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->heartbeat = $container->get(WorkerHeartbeat::class);
        $this->connection->executeStatement('DELETE FROM worker_heartbeat');
    }

    public function testNeverSeenWorkerHasNoLastSeen(): void
    {
        self::assertNull($this->heartbeat->lastSeenAt());
    }

    public function testBeatStoresTheClockNowAndBeatAgainReplacesIt(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-10-25T12:00:00.123456+02:00'));
        $this->heartbeat->beat();
        self::assertSame('2026-10-25T10:00:00.123456+00:00', $this->heartbeat->lastSeenAt()?->format('Y-m-d\TH:i:s.uP'));

        self::mockTime(new \DateTimeImmutable('2026-10-25T10:00:07+00:00'));
        $this->heartbeat->beat();

        self::assertSame('2026-10-25T10:00:07.000000+00:00', $this->heartbeat->lastSeenAt()?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM worker_heartbeat'));
    }
}
