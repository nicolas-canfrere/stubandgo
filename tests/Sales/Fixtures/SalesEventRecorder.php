<?php

declare(strict_types=1);

namespace App\Tests\Sales\Fixtures;

use App\Shared\Event\EventSeatsAllReleased;
use App\Shared\Event\EventSeatsFirstTaken;
use App\Shared\Event\OrderPaid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SalesEventRecorder
{
    /** @var list<object> */
    private array $recorded = [];

    #[AsMessageHandler(bus: 'event.bus')]
    public function onSeatsFirstTaken(EventSeatsFirstTaken $event): void
    {
        $this->recorded[] = $event;
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onSeatsAllReleased(EventSeatsAllReleased $event): void
    {
        $this->recorded[] = $event;
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onOrderPaid(OrderPaid $event): void
    {
        $this->recorded[] = $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function recorded(string $class): array
    {
        return array_values(array_filter($this->recorded, static fn(object $event): bool => $event instanceof $class));
    }
}
