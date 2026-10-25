<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Catalog\Core\Application\Command\CreateEvent;
use App\Catalog\Core\Application\Query\GetBackofficeEvent;
use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Catalog\UI\Http\CatalogJson;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Identity\EventId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class CreateEventController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/backoffice/events', name: 'catalog_backoffice_create_event', methods: 'POST')]
    #[IsGranted('CATALOG_ORGANIZATION_ADMIN')]
    #[OA\Post(
        summary: 'Create a draft event in the organization of the caller.',
        description: 'Requires the Keycloak access token of an administrator of an organization (realm role organization-admin). The organization is the one of the token, never taken from the request. The event starts as a draft, invisible to the public catalog.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventRequest')),
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(response: 201, description: 'The draft event was created; its URL is in the Location header.', headers: [new OA\Header(header: 'Location', description: 'URL of the event.', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(ref: '#/components/schemas/BackofficeEvent')),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of an organization known to the platform (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 422, description: 'Malformed payload (/problems/validation_failed) or a value that breaks a rule: /problems/invalid_value, or /problems/event_start_in_past when the start is not in the future.', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(MemberOrganizationView $organization, #[MapRequestPayload] EventRequest $request): JsonResponse
    {
        $eventId = EventId::generate()->toString();
        $this->commandBus->dispatch(new CreateEvent(
            $eventId,
            $organization->id,
            $request->title,
            $request->description,
            $request->venue,
            $request->startsAt,
            $request->capacity,
            $request->commandPriceTiers(),
        ));

        return new JsonResponse(
            CatalogJson::backofficeEvent($this->queryBus->ask(new GetBackofficeEvent($eventId))),
            Response::HTTP_CREATED,
            ['Location' => '/api/backoffice/events/' . $eventId],
        );
    }
}
