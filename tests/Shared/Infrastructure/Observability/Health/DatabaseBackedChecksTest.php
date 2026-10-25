<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Health;

use App\Shared\Infrastructure\Observability\Health\OutboxHealthCheck;
use App\Shared\Infrastructure\Observability\Health\WorkerHealthCheck;
use App\Shared\Infrastructure\Observability\WorkerHeartbeat;
use App\Shared\Infrastructure\Outbox\OutboxBacklog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DatabaseBackedChecksTest extends TestCase
{
    public function testOutboxCheckReportsUnavailableDatabaseWithoutLeakingTheError(): void
    {
        $status = new OutboxHealthCheck(new OutboxBacklog($this->failingConnection(), new MockClock()))->check();

        self::assertFalse($status->healthy);
        self::assertSame('Outbox unavailable.', $status->detail);
    }

    public function testWorkerCheckReportsUnavailableDatabaseWithoutLeakingTheError(): void
    {
        $clock = new MockClock();
        $status = new WorkerHealthCheck(new WorkerHeartbeat($this->failingConnection(), $clock), $clock)->check();

        self::assertFalse($status->healthy);
        self::assertSame('Worker unavailable.', $status->detail);
    }

    private function failingConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('password authentication failed for s3cret'));

        return $connection;
    }
}
