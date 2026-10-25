<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Health\Fixtures;

use App\Shared\Infrastructure\Observability\Health\HealthCheckInterface;
use App\Shared\Infrastructure\Observability\Health\HealthStatus;

/**
 * Replaces the RabbitMQ check in the test environment, where no broker runs.
 */
final readonly class HealthyRabbitMqCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'rabbitmq';
    }

    public function check(): HealthStatus
    {
        return new HealthStatus(true, 'Stubbed broker.');
    }
}
