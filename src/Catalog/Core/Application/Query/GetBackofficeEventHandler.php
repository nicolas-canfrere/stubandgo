<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Shared\Identity\EventId;

final readonly class GetBackofficeEventHandler
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function __invoke(GetBackofficeEvent $query): BackofficeEventView
    {
        return BackofficeEventView::fromEvent($this->events->get(EventId::fromString($query->eventId)));
    }
}
