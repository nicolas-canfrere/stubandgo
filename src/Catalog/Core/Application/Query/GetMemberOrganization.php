<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<MemberOrganizationView|null>
 */
final readonly class GetMemberOrganization implements QueryInterface
{
    public function __construct(public string $keycloakOrganizationId)
    {
    }
}
