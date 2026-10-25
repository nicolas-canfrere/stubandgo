<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;

interface EventRepositoryInterface
{
    /**
     * Writes every field of the event and its exact list of price tiers: tiers missing from the list are deleted.
     *
     * @throws InvalidValueException when a price tier belongs to another event (nothing is written)
     */
    public function save(Event $event): void;

    /**
     * @throws EventNotFoundException
     */
    public function get(EventId $id): Event;

    /**
     * Loads the event under an exclusive row lock held until the end of the transaction: commands that change the
     * event wait for the orders reading its sale terms, and the other way round.
     *
     * @throws EventNotFoundException
     */
    public function getForUpdate(EventId $id): Event;

    public function find(EventId $id): ?Event;

    /**
     * Loads the event under a key-share row lock held until the end of the transaction: it blocks a concurrent
     * revision or unpublication, but neither another sale nor the update of the lock flag by another order.
     */
    public function findForSale(EventId $id): ?Event;

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

    /**
     * Every event of an organization, whatever its status, by start date then identifier.
     *
     * @return list<Event>
     */
    public function listByOrganization(OrganizationId $organizationId): array;
}
