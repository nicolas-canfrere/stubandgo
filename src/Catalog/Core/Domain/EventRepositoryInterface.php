<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;

interface EventRepositoryInterface
{
    public function save(Event $event): void;

    /**
     * @throws EventNotFoundException
     */
    public function get(EventId $id): Event;

    public function find(EventId $id): ?Event;

    /**
     * Published events starting strictly after $now, by start date then identifier.
     *
     * @return list<Event>
     */
    public function listPublishedStartingAfter(\DateTimeImmutable $now): array;

    /**
     * Published events of an organization starting strictly after $now, by start date then identifier.
     *
     * @return list<Event>
     */
    public function listPublishedByOrganization(OrganizationId $organizationId, \DateTimeImmutable $now): array;
}
