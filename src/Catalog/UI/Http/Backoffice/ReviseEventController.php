<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Catalog\Core\Application\Command\ReviseEvent;
use App\Catalog\Core\Application\Query\GetBackofficeEvent;
use App\Catalog\UI\Http\CatalogJson;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ReviseEventController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/backoffice/events/{eventId}', name: 'catalog_backoffice_revise_event', methods: 'PUT')]
    #[IsGranted('CATALOG_ADMIN_EVENT', subject: 'eventId')]
    #[OA\Put(
        summary: 'Replace every field of a draft event, its price tiers included.',
        description: 'Requires the Keycloak access token of an administrator of an organization (realm role organization-admin). Only an unlocked draft can be revised. A tier with the identifier of a tier of the event is kept, one without identifier is created, one missing from the list is deleted.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventRequest')),
        parameters: [new OA\Parameter(name: 'eventId', description: 'ULID of the event.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(response: 200, description: 'The revised event.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeEvent')),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of the organization that owns the event, or the event does not exist (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 409, description: 'The event is published (/problems/event_not_draft) or locked because seats were taken (/problems/event_locked).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 422, description: 'Malformed payload (/problems/validation_failed) or a value that breaks a rule: /problems/invalid_value (including a tier of another event), or /problems/event_start_in_past.', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(string $eventId, #[MapRequestPayload] EventRequest $request): JsonResponse
    {
        $this->commandBus->dispatch(new ReviseEvent(
            $eventId,
            $request->title,
            $request->description,
            $request->venue,
            $request->startsAt,
            $request->capacity,
            $request->commandPriceTiers(),
        ));

        return new JsonResponse(CatalogJson::backofficeEvent($this->queryBus->ask(new GetBackofficeEvent($eventId))));
    }
}
