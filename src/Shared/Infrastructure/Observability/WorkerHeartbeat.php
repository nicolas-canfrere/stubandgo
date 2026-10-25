<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Sign of life of the worker: a single row, upserted by every outbox relay run.
 */
final readonly class WorkerHeartbeat
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function beat(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO worker_heartbeat (id, last_seen_at) VALUES (1, :now) ON CONFLICT (id) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at',
            ['now' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT)],
        );
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        $lastSeenAt = $this->connection->fetchOne('SELECT last_seen_at FROM worker_heartbeat WHERE id = 1');

        return \is_string($lastSeenAt) ? new \DateTimeImmutable($lastSeenAt) : null;
    }
}
