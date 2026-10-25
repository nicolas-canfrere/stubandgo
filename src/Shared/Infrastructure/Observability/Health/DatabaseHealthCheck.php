<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use Doctrine\DBAL\Connection;

final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthStatus
    {
        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            return new HealthStatus(false, 'Database unreachable.');
        }

        return new HealthStatus(true, 'Database reachable.');
    }
}
