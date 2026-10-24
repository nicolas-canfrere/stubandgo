<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Core\Application\Query\GetOrganizationCatalog;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GetOrganizationCatalogController
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    #[Route('/api/organizations/{slug}', name: 'catalog_get_organization_catalog', methods: 'GET')]
    #[OA\Get(
        summary: 'Show an organization and its published events, earliest first.',
        tags: ['Catalog'],
        parameters: [new OA\Parameter(name: 'slug', description: 'URL identifier of the organization.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The organization catalog.', content: new OA\JsonContent(ref: '#/components/schemas/OrganizationCatalog')),
            new OA\Response(
                response: 404,
                description: 'No organization has this slug (problem type /problems/organization_not_found).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(string $slug): JsonResponse
    {
        $catalog = $this->queryBus->ask(new GetOrganizationCatalog($slug));

        return new JsonResponse([
            'slug' => $catalog->slug,
            'name' => $catalog->name,
            'events' => array_map(CatalogJson::summary(...), $catalog->events),
        ]);
    }
}
