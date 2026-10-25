<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\PriceTier;
use App\Shared\Identity\EventId;

final readonly class RepositoryEventSaleTermsProvider implements EventSaleTermsProviderInterface
{
    public function __construct(
        private EventRepositoryInterface $events,
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * Read by PlaceOrder inside its transaction: the key-share lock taken on the event lasts until the order commits,
     * so a concurrent unpublication either runs first (and the order sees a draft) or waits and finds the event locked.
     */
    public function forEvent(string $eventId): ?EventSaleTerms
    {
        $event = $this->events->findForSale(EventId::fromString($eventId));

        if (null === $event) {
            return null;
        }

        return new EventSaleTerms(
            $event->id()->toString(),
            EventStatus::Published === $event->status(),
            $event->startsAt(),
            $this->organizations->get($event->organizationId())->commissionRate()->toBasisPoints(),
            array_map(static fn(PriceTier $tier): PriceTierTerms => new PriceTierTerms($tier->id()->toString(), (int) $tier->price()->getAmount()), $event->priceTiers()),
        );
    }
}
