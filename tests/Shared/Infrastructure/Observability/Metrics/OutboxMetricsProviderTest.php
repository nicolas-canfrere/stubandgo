<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Metrics;

use App\Shared\Infrastructure\Observability\Metrics\OutboxMetricsProvider;
use App\Tests\Support\Metrics\GaugeLookupTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

final class OutboxMetricsProviderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use GaugeLookupTrait;

    private const string NOW = '2026-10-25T12:00:00+00:00';

    private Connection $connection;
    private OutboxMetricsProvider $provider;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->provider = $container->get(OutboxMetricsProvider::class);
        $this->connection->executeStatement('DELETE FROM outbox_message');
    }

    public function testEmptyOutboxExposesZeros(): void
    {
        $gauges = [...$this->provider->collect()];

        self::assertSame(0.0, self::gaugeValue($gauges, 'stubandgo_outbox_pending_messages'));
        self::assertSame(0.0, self::gaugeValue($gauges, 'stubandgo_outbox_oldest_pending_age_seconds'));
        self::assertSame(0.0, self::gaugeValue($gauges, 'stubandgo_outbox_quarantined_messages'));
    }

    public function testItCountsPendingMessagesAndTheAgeOfTheOldest(): void
    {
        $this->insert('-30 seconds');
        $this->insert('-90 seconds');
        $this->insert('-500 seconds', 'published_at');
        $this->insert('-600 seconds', 'failed_at');

        $gauges = [...$this->provider->collect()];

        self::assertSame(2.0, self::gaugeValue($gauges, 'stubandgo_outbox_pending_messages'));
        self::assertSame(90.0, self::gaugeValue($gauges, 'stubandgo_outbox_oldest_pending_age_seconds'));
    }

    public function testItCountsQuarantinedMessages(): void
    {
        $this->insert('-10 seconds', 'failed_at');
        $this->insert('-20 seconds', 'failed_at');
        $this->insert('-30 seconds');

        $gauges = [...$this->provider->collect()];

        self::assertSame(2.0, self::gaugeValue($gauges, 'stubandgo_outbox_quarantined_messages'));
    }

    private function insert(string $age, ?string $markedColumn = null): void
    {
        $at = new \DateTimeImmutable(self::NOW)->modify($age)->format('Y-m-d H:i:sP');
        $this->connection->executeStatement(
            'INSERT INTO outbox_message (id, message_class, payload, created_at, published_at, failed_at, failure_reason) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), 'App\\Gone', '{}', $at, 'published_at' === $markedColumn ? $at : null, 'failed_at' === $markedColumn ? $at : null, 'failed_at' === $markedColumn ? 'x' : null],
        );
    }
}
