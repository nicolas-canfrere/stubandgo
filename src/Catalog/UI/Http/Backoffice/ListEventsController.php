<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Catalog\Core\Application\Query\BackofficeEventSummaryView;
use App\Catalog\Core\Application\Query\ListOrganizationEvents;
use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Catalog\UI\Http\CatalogJson;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ListEventsController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/backoffice/events', name: 'catalog_backoffice_list_events', methods: 'GET')]
    #[IsGranted('CATALOG_ORGANIZATION_ADMIN')]
    #[OA\Get(
        summary: 'List every event of the organization of the caller, drafts included.',
        description: 'Requires the Keycloak access token of an administrator of an organization (realm role organization-admin). The events are those of the organization of the token, by start date.',
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(response: 200, description: 'The events of the organization, by start date.', content: new OA\JsonContent(required: ['events'], properties: [new OA\Property(property: 'events', type: 'array', items: new OA\Items(ref: '#/components/schemas/BackofficeEventSummary'))])),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of an organization known to the platform (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(MemberOrganizationView $organization): JsonResponse
    {
        return new JsonResponse(['events' => array_map(
            static fn(BackofficeEventSummaryView $event): array => CatalogJson::backofficeSummary($event),
            $this->queryBus->ask(new ListOrganizationEvents($organization->id)),
        )]);
    }
}
