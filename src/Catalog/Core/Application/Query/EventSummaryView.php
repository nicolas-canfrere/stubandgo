<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;

final readonly class EventSummaryView
{
    public function __construct(
        public string $eventId,
        public string $organizationSlug,
        public string $organizationName,
        public string $title,
        public string $venue,
        public \DateTimeImmutable $startsAt,
    ) {
    }

    public static function fromEvent(Event $event, OrganizationReferenceView $organization): self
    {
        return new self($event->id()->toString(), $organization->slug, $organization->name, $event->title()->toString(), $event->venue()->toString(), $event->startsAt());
    }
}
