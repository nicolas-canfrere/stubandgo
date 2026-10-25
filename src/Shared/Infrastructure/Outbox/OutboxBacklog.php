<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Read model of the outbox backlog: pending means neither published nor quarantined.
 */
final readonly class OutboxBacklog
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function pendingCount(): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_message WHERE published_at IS NULL AND failed_at IS NULL');

        return (int) $count;
    }

    public function quarantinedCount(): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM outbox_message WHERE failed_at IS NOT NULL');

        return (int) $count;
    }

    /**
     * Age in whole seconds of the oldest pending message, 0 when nothing is pending.
     */
    public function oldestPendingAgeSeconds(): int
    {
        $createdAt = $this->connection->fetchOne('SELECT MIN(created_at) FROM outbox_message WHERE published_at IS NULL AND failed_at IS NULL');

        return \is_string($createdAt) ? $this->clock->now()->getTimestamp() - new \DateTimeImmutable($createdAt)->getTimestamp() : 0;
    }
}
