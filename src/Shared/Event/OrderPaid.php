<?php

declare(strict_types=1);

namespace App\Shared\Event;

use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;

final readonly class OrderPaid
{
    /**
     * @param list<OrderLine> $lines
     */
    public function __construct(
        public OrderId $orderId,
        public EventId $eventId,
        public EmailAddress $buyerEmail,
        public array $lines,
    ) {
    }
}
