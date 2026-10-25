<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

final readonly class EventDisplay
{
    /**
     * @param array<string, string> $priceTierLabels price tier labels by price tier identifier
     * @param int                   $capacity        number of seats of the event
     */
    public function __construct(
        public string $title,
        public string $venue,
        public \DateTimeImmutable $startsAt,
        public array $priceTierLabels,
        public int $capacity,
    ) {
    }
}
