<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Identity\EventId;

final readonly class RepositoryEventDisplayProvider implements EventDisplayProviderInterface
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function forEvent(string $eventId): ?EventDisplay
    {
        $event = $this->events->find(EventId::fromString($eventId));

        if (null === $event) {
            return null;
        }

        $labels = [];
        foreach ($event->priceTiers() as $tier) {
            $labels[$tier->id()->toString()] = $tier->label()->toString();
        }

        return new EventDisplay($event->title()->toString(), $event->venue()->toString(), $event->startsAt(), $labels);
    }
}
