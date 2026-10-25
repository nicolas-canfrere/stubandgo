<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

interface EventOrganizationProviderInterface
{
    /**
     * The Keycloak organization identifier of the organization that owns the event.
     *
     * Null when the identifier is not a ULID, when the event does not exist, or when its organization is not linked
     * to a Keycloak organization.
     */
    public function keycloakOrganizationIdOf(string $eventId): ?string;
}
