<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Platform;

use App\Catalog\Core\Application\Query\ListOrganizations;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ListOrganizationsController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/platform/organizations', name: 'catalog_platform_list_organizations', methods: 'GET')]
    #[IsGranted('CATALOG_PLATFORM_ADMIN')]
    #[OA\Get(
        summary: 'List every organization of the platform, by name.',
        description: 'Requires the Keycloak access token of a platform administrator (staff realm role platform-admin).',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The organizations, in the alphabetical order of their names.',
                content: new OA\JsonContent(
                    required: ['organizations'],
                    properties: [new OA\Property(property: 'organizations', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrganizationAdmin'))],
                ),
            ),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not a platform administrator (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['organizations' => $this->queryBus->ask(new ListOrganizations())]);
    }
}
