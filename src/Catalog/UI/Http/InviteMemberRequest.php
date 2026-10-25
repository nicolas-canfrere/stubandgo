<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use OpenApi\Attributes as OA;

/**
 * Structure of the invitation body: the format of the email address and the role are checked by the domain.
 */
final readonly class InviteMemberRequest
{
    public function __construct(
        #[OA\Property(description: 'Email address of the invited member; it must not belong to a Keycloak user yet.', example: 'nouveau@rock-asso.test')]
        public string $email,
        #[OA\Property(description: 'Role of the invited member in the organization.', enum: ['admin', 'controller'], example: 'controller')]
        public string $role,
    ) {
    }
}
