<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health report, not a problem: it answers application/json on both 200 and 503.
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
    #[OA\Get(
        summary: 'Report the health of the database, RabbitMQ, the outbox and the worker.',
        description: 'Not cached. This route is not protected in L1.',
        tags: ['Observability'],
        responses: [
            new OA\Response(response: 200, description: 'Every check passes.', content: new OA\JsonContent(ref: '#/components/schemas/HealthReport')),
            new OA\Response(response: 503, description: 'At least one check fails.', content: new OA\JsonContent(ref: '#/components/schemas/HealthReport')),
        ],
    )]
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
