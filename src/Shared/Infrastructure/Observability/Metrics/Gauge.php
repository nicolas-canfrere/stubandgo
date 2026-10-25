<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

final readonly class Gauge
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $name,
        public string $help,
        public float $value,
        public array $labels = [],
    ) {
    }
}
