<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Shared\Exception\InvalidValueException;

/**
 * An identifier that is not a Keycloak organization UUID belongs to no organization.
 */
final readonly class GetMemberOrganizationHandler
{
    public function __construct(private OrganizationRepositoryInterface $organizations)
    {
    }

    public function __invoke(GetMemberOrganization $query): ?MemberOrganizationView
    {
        try {
            $keycloakId = KeycloakOrganizationId::fromString($query->keycloakOrganizationId);
        } catch (InvalidValueException) {
            return null;
        }

        $organization = $this->organizations->findByKeycloakId($keycloakId);

        return null === $organization ? null : MemberOrganizationView::fromOrganization($organization);
    }
}
