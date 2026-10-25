<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Event;

/**
 * An event of an organization as its administrators list it, drafts included.
 */
final readonly class BackofficeEventSummaryView
{
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $startsAt,
        public string $status,
        public bool $locked,
    ) {
    }

    public static function fromEvent(Event $event): self
    {
        return new self($event->id()->toString(), $event->title()->toString(), $event->startsAt(), $event->status()->value, $event->isLocked());
    }
}
