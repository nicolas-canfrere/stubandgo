<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\PriceTier;

final readonly class EventDetailView
{
    /**
     * @param list<PriceTierView> $priceTiers
     */
    public function __construct(
        public string $eventId,
        public string $organizationId,
        public string $organizationSlug,
        public string $title,
        public string $description,
        public string $venue,
        public \DateTimeImmutable $startsAt,
        public int $capacity,
        public array $priceTiers,
    ) {
    }

    public static function fromEvent(Event $event, string $organizationSlug): self
    {
        return new self(
            $event->id()->toString(),
            $event->organizationId()->toString(),
            $organizationSlug,
            $event->title()->toString(),
            $event->description()->toString(),
            $event->venue()->toString(),
            $event->startsAt(),
            $event->capacity()->toInt(),
            array_map(static fn(PriceTier $tier): PriceTierView => PriceTierView::fromPriceTier($tier), $event->priceTiers()),
        );
    }
}
