<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\Slug;

final readonly class GetOrganizationCatalogHandler
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private EventRepositoryInterface $events,
    ) {
    }

    public function __invoke(GetOrganizationCatalog $query): OrganizationCatalogView
    {
        $organization = $this->organizations->getBySlug(Slug::fromString($query->slug));

        return new OrganizationCatalogView(
            $organization->id()->toString(),
            $organization->name()->toString(),
            $organization->slug()->toString(),
            array_map(
                static fn(Event $event): EventSummaryView => EventSummaryView::fromEvent($event),
                $this->events->listPublishedByOrganization($organization->id()),
            ),
        );
    }
}
