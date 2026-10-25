<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Identity\EventId;
use Psr\Clock\ClockInterface;

final readonly class PublishEventHandler
{
    public function __construct(
        private EventRepositoryInterface $events,
        private EventBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PublishEvent $command): void
    {
        $event = $this->events->getForUpdate(EventId::fromString($command->eventId));
        $published = $event->publish($this->clock->now());
        $this->events->save($event);
        $this->eventBus->publish($published);
    }
}
