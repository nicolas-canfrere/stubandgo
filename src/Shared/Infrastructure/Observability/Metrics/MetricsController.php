<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus scrape endpoint. Outside /api, so not in openapi.yaml.
 */
final readonly class MetricsController
{
    /**
     * @param iterable<MetricsProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.metrics_provider')]
        private iterable $providers,
        private PrometheusTextRenderer $renderer,
    ) {
    }

    #[Route('/metrics', name: 'observability_metrics', methods: 'GET')]
    public function __invoke(): Response
    {
        return new PrometheusTextResponse($this->renderer->render($this->gauges()));
    }

    /**
     * @return \Generator<Gauge>
     */
    private function gauges(): \Generator
    {
        foreach ($this->providers as $provider) {
            yield from $provider->collect();
        }
    }
}
