<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use App\Sales\Core\Application\Query\GetEventAvailability;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetEventAvailabilityController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/events/{eventId}/availability', name: 'sales_get_event_availability', methods: 'GET')]
    #[OA\Get(
        summary: 'Show how many seats are still available for an event.',
        tags: ['Sales'],
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The remaining seats.',
                content: new OA\JsonContent(
                    required: ['eventId', 'remaining'],
                    properties: [
                        new OA\Property(property: 'eventId', type: 'string'),
                        new OA\Property(property: 'remaining', type: 'integer', example: 42),
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'The event is not on sale (/problems/event_not_on_sale) or its identifier is not a valid ULID (/problems/invalid_value).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(string $eventId): JsonResponse
    {
        $availability = $this->queryBus->ask(new GetEventAvailability($eventId));

        return new JsonResponse(['eventId' => $availability->eventId, 'remaining' => $availability->remaining]);
    }
}
