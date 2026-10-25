<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\ValueObject\Capacity;
use Psr\Clock\ClockInterface;

final readonly class CreateEventHandler
{
    public function __construct(
        private EventRepositoryInterface $events,
        private OrganizationRepositoryInterface $organizations,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateEvent $command): void
    {
        $organizationId = OrganizationId::fromString($command->organizationId);
        $this->organizations->get($organizationId);

        $this->events->save(Event::draft(
            EventId::fromString($command->id),
            $organizationId,
            EventTitle::fromString($command->title),
            EventDescription::fromString($command->description),
            Venue::fromString($command->venue),
            EventInput::startsAt($command->startsAt),
            Capacity::of($command->capacity),
            EventInput::priceTiers($command->priceTiers),
            $this->clock->now(),
        ));
    }
}
