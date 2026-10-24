<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Event\EventSeatsAllReleased;
use App\Shared\Event\EventSeatsFirstTaken;
use App\Shared\Identity\EventId;
use App\Tests\Catalog\AbstractCatalogTestCase;

final class EventLockingListenersTest extends AbstractCatalogTestCase
{
    public function testSeatsFirstTakenLocksEvent(): void
    {
        $eventId = $this->publishedEvent();
        self::assertFalse($this->isLocked($eventId));

        $this->events()->publish(new EventSeatsFirstTaken(EventId::fromString($eventId)));

        self::assertTrue($this->isLocked($eventId));
    }

    public function testSeatsAllReleasedUnlocksEvent(): void
    {
        $eventId = $this->publishedEvent();
        $this->events()->publish(new EventSeatsFirstTaken(EventId::fromString($eventId)));

        $this->events()->publish(new EventSeatsAllReleased(EventId::fromString($eventId)));

        self::assertFalse($this->isLocked($eventId));
    }

    public function testLockingDoesNotTouchTheOtherEvents(): void
    {
        $eventId = $this->publishedEvent();
        $otherId = $this->publishedEvent();

        $this->events()->publish(new EventSeatsFirstTaken(EventId::fromString($eventId)));

        self::assertFalse($this->isLocked($otherId));
    }

    public function testLockingAnUnknownEventFails(): void
    {
        $this->expectException(EventNotFoundException::class);

        $this->events()->publish(new EventSeatsFirstTaken(EventId::generate()));
    }

    public function testUnlockingAnUnknownEventFails(): void
    {
        $this->expectException(EventNotFoundException::class);

        $this->events()->publish(new EventSeatsAllReleased(EventId::generate()));
    }

    private function publishedEvent(): string
    {
        return $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true);
    }

    private function events(): EventBusInterface
    {
        return self::getContainer()->get(EventBusInterface::class);
    }

    private function isLocked(string $eventId): bool
    {
        return self::getContainer()->get(EventRepositoryInterface::class)->get(EventId::fromString($eventId))->isLocked();
    }
}
