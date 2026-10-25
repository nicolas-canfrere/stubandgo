<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Platform;

use App\Catalog\Core\Application\Command\CreateOrganization;
use App\Catalog\Core\Application\Command\ProvisionOrganization;
use App\Catalog\Core\Application\Query\GetOrganizationAdmin;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Exception\AbstractUpstreamFailureException;
use App\Shared\Identity\OrganizationId;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The organization is created in its own transaction, then provisioned in Keycloak: a Keycloak outage does not undo
 * the creation, the organization stays pending_keycloak until the provisioning is retried.
 */
final readonly class CreateOrganizationController
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/platform/organizations', name: 'catalog_platform_create_organization', methods: 'POST')]
    #[IsGranted('CATALOG_PLATFORM_ADMIN')]
    #[OA\Post(
        summary: 'Create an organization and provision it in Keycloak.',
        description: 'Requires the Keycloak access token of a platform administrator (staff realm role platform-admin). When Keycloak is unreachable the organization is created anyway and answers with the status pending_keycloak: retry with the provisioning route.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateOrganizationRequest')),
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'The organization was created, active or pending_keycloak.',
                content: new OA\JsonContent(ref: '#/components/schemas/OrganizationAdmin'),
            ),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not a platform administrator (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 409, description: 'The slug or the name is already used by another organization (/problems/organization_slug_taken or /problems/organization_name_taken).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 422, description: 'Malformed payload (/problems/validation_failed, with a violations member) or a value that breaks a rule: /problems/invalid_value (name, slug or commission rate).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(#[MapRequestPayload] CreateOrganizationRequest $request): JsonResponse
    {
        $organizationId = OrganizationId::generate()->toString();
        $this->commandBus->dispatch(new CreateOrganization($organizationId, $request->name, $request->slug, $request->commissionBasisPoints));

        try {
            $this->commandBus->dispatch(new ProvisionOrganization($organizationId));
        } catch (AbstractUpstreamFailureException $exception) {
            // Keycloak is down: the organization stays pending_keycloak and the provisioning can be retried.
            $this->logger->warning('organization.provisioning_deferred', ['organization_id' => $organizationId, 'error_code' => $exception->errorCode()]);
        }

        return new JsonResponse($this->queryBus->ask(new GetOrganizationAdmin($organizationId)), Response::HTTP_CREATED);
    }
}
