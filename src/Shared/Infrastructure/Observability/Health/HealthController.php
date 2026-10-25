<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health report, not a problem: it answers application/json on both 200 and 503. Outside /api, so not in openapi.yaml.
 */
final readonly class HealthController
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('app.health_check')]
        private iterable $checks,
    ) {
    }

    #[Route('/health', name: 'observability_health', methods: 'GET')]
    public function __invoke(): JsonResponse
    {
        $healthy = true;
        $report = [];
        foreach ($this->checks as $check) {
            $status = $check->check();
            $healthy = $healthy && $status->healthy;
            $report[$check->name()] = ['healthy' => $status->healthy, 'detail' => $status->detail];
        }

        $response = new JsonResponse(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $report], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
