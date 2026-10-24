<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

final readonly class EventSaleTerms
{
    /**
     * @param list<PriceTierTerms> $priceTiers
     */
    public function __construct(
        public string $eventId,
        public bool $published,
        public \DateTimeImmutable $startsAt,
        public int $commissionBasisPoints,
        public array $priceTiers,
    ) {
    }
}
