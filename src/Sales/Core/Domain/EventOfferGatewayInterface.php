<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Shared\Identity\EventId;

interface EventOfferGatewayInterface
{
    /**
     * Null only when the event does not exist.
     */
    public function forEvent(EventId $eventId): ?EventOffer;
}
