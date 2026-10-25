<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Infrastructure\Outbox\OutboxBacklog;

/**
 * The outbox is down when its oldest pending message waits for more than 120 seconds; quarantined rows do not count.
 */
final readonly class OutboxHealthCheck implements HealthCheckInterface
{
    private const int MAX_AGE_SECONDS = 120;

    public function __construct(private OutboxBacklog $backlog)
    {
    }

    public function name(): string
    {
        return 'outbox';
    }

    public function check(): HealthStatus
    {
        $age = $this->backlog->oldestPendingAgeSeconds();

        return new HealthStatus(
            $age <= self::MAX_AGE_SECONDS,
            \sprintf('Oldest pending message is %d s old (limit %d s).', $age, self::MAX_AGE_SECONDS),
        );
    }
}
