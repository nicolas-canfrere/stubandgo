<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Event\EventPublished;
use App\Shared\Event\EventSeatsAllReleased;
use App\Shared\Event\EventSeatsFirstTaken;
use App\Shared\Event\OrderPaid;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\Infrastructure\Observability\DomainEventLogListener;
use App\Shared\ValueObject\Capacity;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class DomainEventLogListenerTest extends TestCase
{
    private TestHandler $handler;
    private DomainEventLogListener $listener;
    private EventId $eventId;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->listener = new DomainEventLogListener(new Logger('test', [$this->handler]));
        $this->eventId = EventId::generate();
    }

    public function testEventPublishedIsLoggedWithItsEventId(): void
    {
        $this->listener->onEventPublished(new EventPublished($this->eventId, Capacity::of(10)));

        $this->assertLogged(['event' => EventPublished::class, 'event_id' => $this->eventId->toString()]);
    }

    public function testEventSeatsFirstTakenIsLoggedWithItsEventId(): void
    {
        $this->listener->onEventSeatsFirstTaken(new EventSeatsFirstTaken($this->eventId));

        $this->assertLogged(['event' => EventSeatsFirstTaken::class, 'event_id' => $this->eventId->toString()]);
    }

    public function testEventSeatsAllReleasedIsLoggedWithItsEventId(): void
    {
        $this->listener->onEventSeatsAllReleased(new EventSeatsAllReleased($this->eventId));

        $this->assertLogged(['event' => EventSeatsAllReleased::class, 'event_id' => $this->eventId->toString()]);
    }

    public function testOrderPaidIsLoggedWithItsIdsOnly(): void
    {
        $orderId = OrderId::generate();
        $priceTierId = PriceTierId::generate();

        $this->listener->onOrderPaid(new OrderPaid($orderId, $this->eventId, EmailAddress::fromString('secret.buyer@example.com'), [new OrderLine($priceTierId, Quantity::of(2))]));

        $this->assertLogged(['event' => OrderPaid::class, 'order_id' => $orderId->toString(), 'event_id' => $this->eventId->toString()]);
        $logged = json_encode($this->handler->getRecords()[0]->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret.buyer', $logged);
        self::assertStringNotContainsString($priceTierId->toString(), $logged);
    }

    /**
     * @param array<string, string> $context
     */
    private function assertLogged(array $context): void
    {
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('domain_event', $records[0]->message);
        self::assertSame($context, $records[0]->context);
    }
}
