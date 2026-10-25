<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure\Metrics;

use App\Access\Core\Domain\ScanOutcome;
use App\Access\Infrastructure\Metrics\AccessMetricsProvider;
use App\Shared\Identity\EventId;
use App\Shared\Infrastructure\Observability\Metrics\Gauge;
use App\Tests\Support\Metrics\GaugeLookupTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class AccessMetricsProviderTest extends KernelTestCase
{
    use GaugeLookupTrait;

    private Connection $connection;
    private AccessMetricsProvider $provider;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->provider = $container->get(AccessMetricsProvider::class);
    }

    public function testEveryScanOutcomeIsExposed(): void
    {
        $gauges = [...$this->provider->collect()];

        foreach (ScanOutcome::cases() as $outcome) {
            self::assertNotNull(self::gaugeValue($gauges, 'stubandgo_scans', ['outcome' => $outcome->value]), $outcome->value);
        }
    }

    public function testMetricsCountScansByOutcome(): void
    {
        $before = [...$this->provider->collect()];
        $eventId = EventId::generate()->toString();
        $this->scan($eventId, 'valid');
        $this->scan($eventId, 'unknown');
        $this->scan($eventId, 'unknown');
        $this->scan($eventId, 'wrong_event');

        $after = [...$this->provider->collect()];

        self::assertSame(1.0, $this->delta($before, $after, 'valid'));
        self::assertSame(2.0, $this->delta($before, $after, 'unknown'));
        self::assertSame(1.0, $this->delta($before, $after, 'wrong_event'));
        self::assertSame(0.0, $this->delta($before, $after, 'already_used'));
    }

    public function testMetricsCountUndeliveredTicketBundles(): void
    {
        $before = self::gaugeValue($this->provider->collect(), 'stubandgo_ticket_bundles_undelivered');
        $this->bundle(deliveredAt: null);
        $this->bundle(deliveredAt: null);
        $this->bundle(deliveredAt: '2026-10-24T12:00:00+00:00');

        $after = self::gaugeValue($this->provider->collect(), 'stubandgo_ticket_bundles_undelivered');

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame(2.0, $after - $before);
    }

    /**
     * @param iterable<Gauge> $before
     * @param iterable<Gauge> $after
     */
    private function delta(iterable $before, iterable $after, string $outcome): float
    {
        return (self::gaugeValue($after, 'stubandgo_scans', ['outcome' => $outcome]) ?? 0.0) - (self::gaugeValue($before, 'stubandgo_scans', ['outcome' => $outcome]) ?? 0.0);
    }

    private function scan(string $eventId, string $outcome): void
    {
        $this->connection->executeStatement(
            'INSERT INTO scan_attempt (id, event_id, token_fingerprint, outcome, scanned_at) VALUES (?, ?, ?, ?, now())',
            [new Ulid()->toBase32(), $eventId, hash('sha256', 't'), $outcome],
        );
    }

    private function bundle(?string $deliveredAt): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ticket_bundle (order_id, event_id, buyer_email, delivered_at) VALUES (?, ?, 'buyer@example.com', ?)",
            [new Ulid()->toBase32(), EventId::generate()->toString(), $deliveredAt],
        );
    }
}
