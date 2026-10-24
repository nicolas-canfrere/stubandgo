<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\TicketDeliveryRequesterInterface;
use App\Access\Infrastructure\Messenger\DeliverTicketsMessage;
use App\Shared\Identity\OrderId;
use App\Shared\Infrastructure\Outbox\OutboxWriterInterface;

final readonly class OutboxTicketDeliveryRequester implements TicketDeliveryRequesterInterface
{
    public function __construct(private OutboxWriterInterface $outbox)
    {
    }

    public function request(OrderId $orderId): void
    {
        $this->outbox->store(new DeliverTicketsMessage($orderId->toString()));
    }
}
