<?php

declare(strict_types=1);

namespace App\Tests\Support\Metrics;

use App\Shared\Infrastructure\Observability\Metrics\Gauge;

trait GaugeLookupTrait
{
    /**
     * @param iterable<Gauge>       $gauges
     * @param array<string, string> $labels
     */
    private static function gaugeValue(iterable $gauges, string $name, array $labels = []): ?float
    {
        foreach ($gauges as $gauge) {
            if ($gauge->name === $name && $gauge->labels === $labels) {
                return $gauge->value;
            }
        }

        return null;
    }
}
