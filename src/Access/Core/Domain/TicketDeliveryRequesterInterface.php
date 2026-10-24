<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Shared\Identity\OrderId;

interface TicketDeliveryRequesterInterface
{
    /**
     * Asks, durably, for the tickets of the order to be delivered to the buyer.
     */
    public function request(OrderId $orderId): void;
}
