<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

use App\Shared\Infrastructure\Observability\WorkerHeartbeat;

final readonly class WorkerMetricsProvider implements MetricsProviderInterface
{
    public function __construct(private WorkerHeartbeat $heartbeat)
    {
    }

    public function collect(): iterable
    {
        $lastSeenAt = $this->heartbeat->lastSeenAt();
        if (null !== $lastSeenAt) {
            yield new Gauge('stubandgo_worker_last_seen_timestamp_seconds', 'Unix time of the last outbox relay run of the worker.', $lastSeenAt->getTimestamp());
        }
    }
}
