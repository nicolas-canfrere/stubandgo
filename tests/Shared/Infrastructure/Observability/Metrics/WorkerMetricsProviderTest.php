<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Metrics;

use App\Shared\Infrastructure\Observability\Metrics\WorkerMetricsProvider;
use App\Tests\Support\Metrics\GaugeLookupTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerMetricsProviderTest extends KernelTestCase
{
    use GaugeLookupTrait;

    private Connection $connection;
    private WorkerMetricsProvider $provider;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->provider = $container->get(WorkerMetricsProvider::class);
        $this->connection->executeStatement('DELETE FROM worker_heartbeat');
    }

    public function testItExposesTheLastHeartbeatAsUnixTimestamp(): void
    {
        $this->connection->executeStatement("INSERT INTO worker_heartbeat (id, last_seen_at) VALUES (1, '2026-10-25 12:00:00+00')");

        $gauges = [...$this->provider->collect()];

        self::assertCount(1, $gauges);
        self::assertSame(1792929600.0, self::gaugeValue($gauges, 'stubandgo_worker_last_seen_timestamp_seconds'));
    }

    public function testItExposesNothingWhenTheWorkerWasNeverSeen(): void
    {
        self::assertSame([], [...$this->provider->collect()]);
    }
}
