<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Platform;

use App\Catalog\Core\Application\Command\ChangeCommissionRate;
use App\Catalog\Core\Application\Query\GetOrganizationAdmin;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class ChangeCommissionRateController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/api/platform/organizations/{organizationId}/commission-rate', name: 'catalog_platform_change_commission_rate', methods: 'PUT')]
    #[IsGranted('CATALOG_PLATFORM_ADMIN')]
    #[OA\Put(
        summary: 'Change the commission rate of an organization.',
        description: 'Requires the Keycloak access token of a platform administrator (staff realm role platform-admin). The new rate applies to the orders placed afterwards.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChangeCommissionRateRequest')),
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'organizationId', description: 'ULID of the organization.', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The organization with its new rate.', content: new OA\JsonContent(ref: '#/components/schemas/OrganizationAdmin')),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not a platform administrator (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 404, description: 'No organization has this identifier (/problems/organization_not_found).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 422, description: 'Malformed payload (/problems/validation_failed) or a rate outside 0 to 10000 basis points (/problems/invalid_value).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(string $organizationId, #[MapRequestPayload] ChangeCommissionRateRequest $request): JsonResponse
    {
        $this->commandBus->dispatch(new ChangeCommissionRate($organizationId, $request->commissionBasisPoints));

        return new JsonResponse($this->queryBus->ask(new GetOrganizationAdmin($organizationId)));
    }
}
