<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Listener;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Event\EventSeatsFirstTaken;

final readonly class LockEventOnSeatsFirstTaken
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function __invoke(EventSeatsFirstTaken $event): void
    {
        $catalogEvent = $this->events->get($event->eventId);
        $catalogEvent->lock();
        $this->events->save($catalogEvent);
    }
}
