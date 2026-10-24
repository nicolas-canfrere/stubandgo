<?php

declare(strict_types=1);

namespace App\Sales\UI\Http;

use OpenApi\Attributes as OA;

/**
 * Structure of the order placement body: business rules (size, email, price tiers) are checked by the domain.
 */
final readonly class PlaceOrderRequest
{
    /**
     * @param list<OrderLineRequest> $lines
     */
    public function __construct(
        #[OA\Property(description: 'ULID of the event.')]
        public string $eventId,
        #[OA\Property(example: 'buyer@example.com')]
        public string $buyerEmail,
        public array $lines,
    ) {
    }
}
