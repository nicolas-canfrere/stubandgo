<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

final readonly class HealthStatus
{
    public function __construct(public bool $healthy, public string $detail)
    {
    }
}
