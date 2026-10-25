<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Platform;

use App\Catalog\Core\Application\Command\ProvisionOrganization;
use App\Catalog\Core\Application\Query\GetOrganizationAdmin;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ProvisionOrganizationController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/platform/organizations/{organizationId}/provisioning', name: 'catalog_platform_provision_organization', methods: 'POST')]
    #[IsGranted('CATALOG_PLATFORM_ADMIN')]
    #[OA\Post(
        summary: 'Provision an organization in Keycloak, or retry it.',
        description: 'Requires the Keycloak access token of a platform administrator (staff realm role platform-admin). Idempotent: an organization that is already active is returned as is, without calling Keycloak.',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'organizationId', description: 'ULID of the organization.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The organization, active.', content: new OA\JsonContent(ref: '#/components/schemas/OrganizationAdmin')),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not a platform administrator (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 404, description: 'No organization has this identifier (/problems/organization_not_found).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 502, description: 'Keycloak is unreachable or refused the request (/problems/identity_provider_unavailable); the organization stays pending_keycloak.', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(string $organizationId): JsonResponse
    {
        $this->commandBus->dispatch(new ProvisionOrganization($organizationId));

        return new JsonResponse($this->queryBus->ask(new GetOrganizationAdmin($organizationId)));
    }
}
