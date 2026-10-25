<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\PriceTier;

/**
 * An event of an organization with everything its administrators can edit.
 */
final readonly class BackofficeEventView
{
    /**
     * @param list<PriceTierView> $priceTiers
     */
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $startsAt,
        public string $status,
        public bool $locked,
        public string $description,
        public string $venue,
        public int $capacity,
        public array $priceTiers,
    ) {
    }

    public static function fromEvent(Event $event): self
    {
        return new self(
            $event->id()->toString(),
            $event->title()->toString(),
            $event->startsAt(),
            $event->status()->value,
            $event->isLocked(),
            $event->description()->toString(),
            $event->venue()->toString(),
            $event->capacity()->toInt(),
            array_map(static fn(PriceTier $tier): PriceTierView => PriceTierView::fromPriceTier($tier), $event->priceTiers()),
        );
    }
}
