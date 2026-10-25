<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

/**
 * Replaces every field of a draft event and its complete list of price tiers: a tier missing from the list is
 * deleted, a tier with a new identifier is created.
 */
final readonly class ReviseEvent implements CommandInterface
{
    /**
     * @param string                                                  $startsAt   ISO 8601 date-time with offset
     * @param list<array{id: string, label: string, priceCents: int}> $priceTiers
     */
    public function __construct(
        public string $eventId,
        public string $title,
        public string $description,
        public string $venue,
        public string $startsAt,
        public int $capacity,
        public array $priceTiers,
    ) {
    }
}
