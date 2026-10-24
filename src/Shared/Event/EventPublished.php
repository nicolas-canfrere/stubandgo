<?php

declare(strict_types=1);

namespace App\Shared\Event;

use App\Shared\Identity\EventId;
use App\Shared\Model\Capacity;

final readonly class EventPublished
{
    public function __construct(
        public EventId $eventId,
        public Capacity $capacity,
    ) {
    }
}
