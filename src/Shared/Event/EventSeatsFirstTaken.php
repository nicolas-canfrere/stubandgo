<?php

declare(strict_types=1);

namespace App\Shared\Event;

use App\Shared\Identity\EventId;

final readonly class EventSeatsFirstTaken
{
    public function __construct(public EventId $eventId)
    {
    }
}
