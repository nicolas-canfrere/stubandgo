<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Source of the gauges exposed by /metrics. Gauges of one name must be yielded together.
 */
#[AutoconfigureTag('app.metrics_provider')]
interface MetricsProviderInterface
{
    /**
     * @return iterable<Gauge>
     */
    public function collect(): iterable;
}
