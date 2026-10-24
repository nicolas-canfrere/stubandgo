<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Command;

use App\Access\Core\Domain\EventDisplayGatewayInterface;
use App\Access\Core\Domain\Exception\TicketBundleNotFoundException;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Core\Domain\TicketMailerInterface;
use App\Shared\Identity\OrderId;
use Psr\Clock\ClockInterface;

/**
 * Delivery is at least once and idempotent: the bundle row is locked, so of two concurrent deliveries of one
 * order the second finds the bundle delivered and sends nothing.
 */
final readonly class DeliverTicketsHandler
{
    public function __construct(
        private TicketBundleRepositoryInterface $bundles,
        private EventDisplayGatewayInterface $events,
        private TicketMailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TicketBundleNotFoundException
     */
    public function __invoke(DeliverTickets $command): void
    {
        $bundle = $this->bundles->getForUpdate(OrderId::fromString($command->orderId));

        if ($bundle->isDelivered()) {
            return;
        }

        $this->mailer->send($bundle, $this->events->forEvent($bundle->eventId()));
        $bundle->markDelivered($this->clock->now());
        $this->bundles->save($bundle);
    }
}
