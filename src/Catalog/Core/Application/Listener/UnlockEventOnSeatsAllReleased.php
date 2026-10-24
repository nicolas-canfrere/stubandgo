<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Listener;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Event\EventSeatsAllReleased;

final readonly class UnlockEventOnSeatsAllReleased
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function __invoke(EventSeatsAllReleased $event): void
    {
        $catalogEvent = $this->events->get($event->eventId);
        $catalogEvent->unlock();
        $this->events->save($catalogEvent);
    }
}
