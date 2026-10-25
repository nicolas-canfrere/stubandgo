<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Shared\Identity\EventId;
use Symfony\Component\Uid\Ulid;

final readonly class RepositoryEventOrganizationProvider implements EventOrganizationProviderInterface
{
    public function __construct(
        private EventRepositoryInterface $events,
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function keycloakOrganizationIdOf(string $eventId): ?string
    {
        if (!Ulid::isValid($eventId)) {
            return null;
        }

        $event = $this->events->find(EventId::fromString($eventId));

        if (null === $event) {
            return null;
        }

        return $this->organizations->get($event->organizationId())->keycloakId()?->toString();
    }
}
