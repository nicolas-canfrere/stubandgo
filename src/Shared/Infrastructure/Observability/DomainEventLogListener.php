<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Event\EventPublished;
use App\Shared\Event\EventSeatsAllReleased;
use App\Shared\Event\EventSeatsFirstTaken;
use App\Shared\Event\OrderPaid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Logs the domain events of the shared kernel with the identifiers they carry, never personal data
 * (OrderPaid's buyer email) nor order lines.
 */
final readonly class DomainEventLogListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onEventPublished(EventPublished $event): void
    {
        $this->log($event, ['event_id' => $event->eventId->toString()]);
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onEventSeatsFirstTaken(EventSeatsFirstTaken $event): void
    {
        $this->log($event, ['event_id' => $event->eventId->toString()]);
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onEventSeatsAllReleased(EventSeatsAllReleased $event): void
    {
        $this->log($event, ['event_id' => $event->eventId->toString()]);
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onOrderPaid(OrderPaid $event): void
    {
        $this->log($event, ['order_id' => $event->orderId->toString(), 'event_id' => $event->eventId->toString()]);
    }

    /**
     * @param array<string, string> $ids
     */
    private function log(object $event, array $ids): void
    {
        $this->logger->info('domain_event', ['event' => $event::class, ...$ids]);
    }
}
