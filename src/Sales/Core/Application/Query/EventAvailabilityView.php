<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

final readonly class EventAvailabilityView
{
    public function __construct(
        public string $eventId,
        public int $remaining,
    ) {
    }
}
