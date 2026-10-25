<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class ListUpcomingEventsHandler
{
    public function __construct(
        private EventRepositoryInterface $events,
        private OrganizationRepositoryInterface $organizations,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<EventSummaryView>
     */
    public function __invoke(ListUpcomingEvents $query): array
    {
        $references = new OrganizationReferences($this->organizations);

        return array_map(
            static fn(Event $event): EventSummaryView => EventSummaryView::fromEvent($event, $references->of($event->organizationId())),
            $this->events->listPublishedStartingAfter($this->clock->now()),
        );
    }
}
