<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Catalog\Core\Application\Command\PublishEvent;
use App\Catalog\Core\Application\Query\GetBackofficeEvent;
use App\Catalog\UI\Http\CatalogJson;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class PublishEventController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/backoffice/events/{eventId}/publication', name: 'catalog_backoffice_publish_event', methods: 'POST')]
    #[IsGranted('CATALOG_ADMIN_EVENT', subject: 'eventId')]
    #[OA\Post(
        summary: 'Publish an event so that it appears in the public catalog and opens for sale.',
        description: 'Requires the Keycloak access token of an administrator of an organization (realm role organization-admin).',
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(response: 200, description: 'The published event.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeEvent')),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of the organization that owns the event, or the event does not exist (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 409, description: 'The event is already published (/problems/event_already_published).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 422, description: 'The event has no price tier (/problems/event_without_price_tier) or starts in the past (/problems/event_start_in_past).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(string $eventId): JsonResponse
    {
        $this->commandBus->dispatch(new PublishEvent($eventId));

        return new JsonResponse(CatalogJson::backofficeEvent($this->queryBus->ask(new GetBackofficeEvent($eventId))));
    }
}
