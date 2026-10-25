<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Identity\EventId;

final readonly class UnpublishEventHandler
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function __invoke(UnpublishEvent $command): void
    {
        $event = $this->events->getForUpdate(EventId::fromString($command->eventId));
        $event->unpublish();
        $this->events->save($event);
    }
}
