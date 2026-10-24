<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Core\Application\Query\ListUpcomingEvents;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListUpcomingEventsController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/events', name: 'catalog_list_upcoming_events', methods: 'GET')]
    #[OA\Get(
        summary: 'List the published events that have not started yet, earliest first.',
        tags: ['Catalog'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The upcoming events.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EventSummary')),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $events = $this->queryBus->ask(new ListUpcomingEvents());

        return new JsonResponse(array_map(CatalogJson::summary(...), $events));
    }
}
