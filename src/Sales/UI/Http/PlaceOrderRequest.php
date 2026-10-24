<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

/**
 * Structure of the order placement body: business rules (size, email, price tiers) are checked by the domain.
 */
final readonly class PlaceOrderRequest
{
    /**
     * @param list<OrderLineRequest> $lines
     */
    public function __construct(
        public string $eventId,
        public string $buyerEmail,
        public array $lines,
    ) {
    }
}
