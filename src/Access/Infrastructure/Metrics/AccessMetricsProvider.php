<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Metrics;

use App\Access\Core\Domain\ScanOutcome;
use App\Shared\Infrastructure\Observability\Metrics\Gauge;
use App\Shared\Infrastructure\Observability\Metrics\MetricsProviderInterface;
use Doctrine\DBAL\Connection;

final readonly class AccessMetricsProvider implements MetricsProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function collect(): iterable
    {
        /** @var array<string, int|string> $counts */
        $counts = $this->connection->fetchAllKeyValue('SELECT outcome, COUNT(*) FROM scan_attempt GROUP BY outcome');
        foreach (ScanOutcome::cases() as $outcome) {
            yield new Gauge('stubandgo_scans', 'Ticket scans by outcome.', (int) ($counts[$outcome->value] ?? 0), ['outcome' => $outcome->value]);
        }
    }
}
