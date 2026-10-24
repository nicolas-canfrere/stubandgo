<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Listener;

use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Core\Domain\TicketDeliveryRequesterInterface;
use App\Access\Core\Domain\TicketTokenGeneratorInterface;
use App\Shared\Event\OrderPaid;

/**
 * Runs inside the transaction of the payment: a failure here rolls the payment back.
 */
final readonly class IssueTicketsOnOrderPaid
{
    public function __construct(
        private TicketBundleRepositoryInterface $bundles,
        private TicketTokenGeneratorInterface $tokenGenerator,
        private TicketDeliveryRequesterInterface $deliveryRequester,
    ) {
    }

    public function __invoke(OrderPaid $event): void
    {
        $this->bundles->save(TicketBundle::issue(
            $event->orderId,
            $event->eventId,
            $event->buyerEmail,
            $event->lines,
            $this->tokenGenerator,
        ));
        $this->deliveryRequester->request($event->orderId);
    }
}
