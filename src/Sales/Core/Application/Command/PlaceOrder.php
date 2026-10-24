<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

final readonly class PlaceOrder implements CommandInterface
{
    /**
     * @param list<array{priceTierId: string, quantity: int}> $lines
     */
    public function __construct(
        public string $orderId,
        public string $eventId,
        public string $buyerEmail,
        public array $lines,
    ) {
    }
}
