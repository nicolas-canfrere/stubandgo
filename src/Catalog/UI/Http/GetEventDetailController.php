<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Core\Application\Query\GetEventDetail;
use App\Catalog\Core\Application\Query\PriceTierView;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetEventDetailController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/events/{eventId}', name: 'catalog_get_event_detail', methods: 'GET')]
    #[OA\Get(
        summary: 'Show a published event with its price tiers.',
        tags: ['Catalog'],
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The event detail.', content: new OA\JsonContent(ref: '#/components/schemas/EventDetail')),
            new OA\Response(
                response: 404,
                description: 'The event does not exist or is not published (problem type /problems/event_not_found).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 422,
                description: 'The event identifier is not a valid ULID (problem type /problems/invalid_value).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(string $eventId): JsonResponse
    {
        $event = $this->queryBus->ask(new GetEventDetail($eventId));

        return new JsonResponse([
            'id' => $event->eventId,
            'title' => $event->title,
            'description' => $event->description,
            'venue' => $event->venue,
            'startsAt' => CatalogJson::date($event->startsAt),
            'capacity' => $event->capacity,
            'organization' => ['slug' => $event->organizationSlug],
            'priceTiers' => array_map(
                static fn(PriceTierView $tier): array => ['id' => $tier->priceTierId, 'label' => $tier->label, 'priceCents' => $tier->priceCents],
                $event->priceTiers,
            ),
        ]);
    }
}
