<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

/**
 * What an event offers for sale, as the organization defined it: its seats and the labels of its price tiers.
 */
final readonly class EventOffer
{
    /**
     * @param array<string, string> $priceTierLabels price tier labels by price tier identifier
     * @param bool                  $onSale          whether the event is published
     */
    public function __construct(
        public int $capacity,
        public array $priceTierLabels,
        public bool $onSale,
    ) {
    }
}
