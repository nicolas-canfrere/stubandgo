<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Metrics;

use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus scrape endpoint.
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
    #[OA\Get(
        summary: 'Expose the application gauges for Prometheus.',
        description: 'Prometheus text exposition format 0.0.4: orders by status, seats taken per event, outbox backlog, scans by outcome, undelivered ticket bundles, worker heartbeat. This route is not protected in L1.',
        tags: ['Observability'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The current value of every gauge.',
                content: new OA\MediaType(mediaType: 'text/plain; version=0.0.4', schema: new OA\Schema(type: 'string', example: "# HELP stubandgo_scans Ticket scans by outcome.\n# TYPE stubandgo_scans gauge\nstubandgo_scans{outcome=\"valid\"} 12\n")),
            ),
        ],
    )]
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
