<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Infrastructure\Observability\WorkerHeartbeat;
use Psr\Clock\ClockInterface;

/**
 * The worker is down when its last relay run is older than 60 seconds, or when it was never seen.
 */
final readonly class WorkerHealthCheck implements HealthCheckInterface
{
    private const int MAX_SILENCE_SECONDS = 60;

    public function __construct(private WorkerHeartbeat $heartbeat, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return 'worker';
    }

    public function check(): HealthStatus
    {
        $lastSeenAt = $this->heartbeat->lastSeenAt();
        if (null === $lastSeenAt) {
            return new HealthStatus(false, 'Worker never seen.');
        }

        $silence = $this->clock->now()->getTimestamp() - $lastSeenAt->getTimestamp();

        return new HealthStatus(
            $silence <= self::MAX_SILENCE_SECONDS,
            \sprintf('Last relay run %d s ago (limit %d s).', $silence, self::MAX_SILENCE_SECONDS),
        );
    }
}
