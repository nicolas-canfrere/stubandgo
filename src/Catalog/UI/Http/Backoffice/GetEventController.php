<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Catalog\Core\Application\Query\GetBackofficeEvent;
use App\Catalog\UI\Http\CatalogJson;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class GetEventController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/backoffice/events/{eventId}', name: 'catalog_backoffice_get_event', methods: 'GET')]
    #[IsGranted('CATALOG_ADMIN_EVENT', subject: 'eventId')]
    #[OA\Get(
        summary: 'Show an event of the organization of the caller, whatever its status.',
        description: 'Requires the Keycloak access token of an administrator of an organization (realm role organization-admin).',
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(response: 200, description: 'The event.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeEvent')),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of the organization that owns the event, or the event does not exist (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(string $eventId): JsonResponse
    {
        return new JsonResponse(CatalogJson::backofficeEvent($this->queryBus->ask(new GetBackofficeEvent($eventId))));
    }
}
