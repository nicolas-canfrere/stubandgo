<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Identity\OrganizationId;

final readonly class ListOrganizationEventsHandler
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    /**
     * @return list<BackofficeEventSummaryView>
     */
    public function __invoke(ListOrganizationEvents $query): array
    {
        return array_map(
            static fn(Event $event): BackofficeEventSummaryView => BackofficeEventSummaryView::fromEvent($event),
            $this->events->listByOrganization(OrganizationId::fromString($query->organizationId)),
        );
    }
}
