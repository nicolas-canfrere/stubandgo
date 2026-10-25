<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Probe of one dependency, reported by /health. Implementations never throw and never put secrets in the detail.
 */
#[AutoconfigureTag('app.health_check')]
interface HealthCheckInterface
{
    public function name(): string;

    public function check(): HealthStatus;
}
