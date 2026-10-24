<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Shared\Identity\EventId;

final readonly class GetEventDetailHandler
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function __invoke(GetEventDetail $query): EventDetailView
    {
        $id = EventId::fromString($query->eventId);
        $event = $this->events->find($id);

        if (null === $event || EventStatus::Published !== $event->status()) {
            throw EventNotFoundException::withId($id);
        }

        return EventDetailView::fromEvent($event);
    }
}
