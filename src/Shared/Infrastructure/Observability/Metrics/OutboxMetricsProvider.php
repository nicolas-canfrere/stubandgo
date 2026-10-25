<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

use App\Shared\Infrastructure\Outbox\OutboxBacklog;

final readonly class OutboxMetricsProvider implements MetricsProviderInterface
{
    public function __construct(private OutboxBacklog $backlog)
    {
    }

    public function collect(): iterable
    {
        yield new Gauge('stubandgo_outbox_pending_messages', 'Outbox messages neither published nor quarantined.', $this->backlog->pendingCount());
        yield new Gauge('stubandgo_outbox_oldest_pending_age_seconds', 'Age in seconds of the oldest pending outbox message, 0 when none.', $this->backlog->oldestPendingAgeSeconds());
        yield new Gauge('stubandgo_outbox_quarantined_messages', 'Outbox messages quarantined after failing to be rebuilt.', $this->backlog->quarantinedCount());
    }
}
