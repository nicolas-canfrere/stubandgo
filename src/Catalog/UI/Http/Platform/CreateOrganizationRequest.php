<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Platform;

use OpenApi\Attributes as OA;

/**
 * Structure of the organization creation body: the format of the name, the slug and the rate is checked by the domain.
 */
final readonly class CreateOrganizationRequest
{
    public function __construct(
        #[OA\Property(description: 'Display name of the organization, unique.', example: 'Rock Asso')]
        public string $name,
        #[OA\Property(description: 'URL identifier, unique: 3 to 64 lowercase letters, digits or single hyphens between them.', example: 'rock-asso')]
        public string $slug,
        #[OA\Property(description: 'Commission of the platform on each sale, in basis points (0 to 10000).')]
        public int $commissionBasisPoints,
    ) {
    }
}
