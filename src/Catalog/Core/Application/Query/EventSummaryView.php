<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;

final readonly class EventSummaryView
{
    public function __construct(
        public string $eventId,
        public string $title,
        public string $venue,
        public \DateTimeImmutable $startsAt,
    ) {
    }

    public static function fromEvent(Event $event): self
    {
        return new self($event->id()->toString(), $event->title()->toString(), $event->venue()->toString(), $event->startsAt());
    }
}
