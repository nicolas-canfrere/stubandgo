<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures\Http;

use App\Shared\Infrastructure\Security\AuthenticatedMember;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Shows what the API knows about the member who presented the access token.
 */
final class WhoAmIController
{
    #[Route('/api/test/whoami', methods: 'GET')]
    #[IsGranted('IS_AUTHENTICATED')]
    public function __invoke(#[CurrentUser] AuthenticatedMember $member): JsonResponse
    {
        return new JsonResponse([
            'realm' => $member->realm()->value,
            'roles' => $member->realmRoles(),
            'keycloakOrganizationId' => $member->keycloakOrganizationId(),
        ]);
    }
}
