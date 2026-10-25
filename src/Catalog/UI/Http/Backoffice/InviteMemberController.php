<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Catalog\Core\Application\Command\InviteMember;
use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Catalog\UI\Http\InviteMemberRequest;
use App\Shared\Bus\CommandBusInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class InviteMemberController
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    #[Route('/api/backoffice/invitations', name: 'catalog_backoffice_invite_member', methods: 'POST')]
    #[IsGranted('CATALOG_ORGANIZATION_ADMIN')]
    #[OA\Post(
        summary: 'Invite a member into the organization of the caller.',
        description: 'Requires the Keycloak access token of an administrator of an organization (realm role organization-admin); the organization is the one of the token, never taken from the request. Keycloak sends the invitation email.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InviteMemberRequest')),
        security: [['bearerAuth' => []]],
        tags: ['Backoffice'],
        responses: [
            new OA\Response(response: 204, description: 'The invitation was sent.'),
            new OA\Response(response: 401, description: 'Access token missing or invalid (/problems/unauthorized, with a WWW-Authenticate: Bearer challenge).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 403, description: 'The member is not an administrator of an organization known to the platform (/problems/forbidden).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 409, description: 'The email address is already known to Keycloak (/problems/member_already_exists), or the organization is not provisioned in Keycloak yet (/problems/organization_not_active).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 422, description: 'Malformed payload (/problems/validation_failed) or an invalid email address or role (/problems/invalid_value).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
            new OA\Response(response: 502, description: 'Keycloak is unreachable or refused the request (/problems/identity_provider_unavailable).', content: new OA\JsonContent(ref: '#/components/schemas/Problem')),
        ],
    )]
    public function __invoke(MemberOrganizationView $organization, #[MapRequestPayload] InviteMemberRequest $request): Response
    {
        $this->commandBus->dispatch(new InviteMember($organization->id, $request->email, $request->role));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
