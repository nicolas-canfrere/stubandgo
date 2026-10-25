<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Identity\EventId;
use App\Shared\ValueObject\Capacity;
use Psr\Clock\ClockInterface;

final readonly class ReviseEventHandler
{
    public function __construct(
        private EventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReviseEvent $command): void
    {
        $event = $this->events->getForUpdate(EventId::fromString($command->eventId));
        $event->revise(
            EventTitle::fromString($command->title),
            EventDescription::fromString($command->description),
            Venue::fromString($command->venue),
            EventInput::startsAt($command->startsAt),
            Capacity::of($command->capacity),
            EventInput::revisedPriceTiers($command->priceTiers, $event),
            $this->clock->now(),
        );
        $this->events->save($event);
    }
}
