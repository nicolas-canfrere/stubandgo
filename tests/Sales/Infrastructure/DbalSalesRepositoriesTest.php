<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure;

use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\Exception\OrderNotFoundException;
use App\Sales\Core\Domain\Order;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Sales\Core\Domain\OrderStatus;
use App\Sales\Core\Domain\PaymentOutcome;
use App\Sales\Core\Domain\SeatStockRepositoryInterface;
use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\PricedOrderLine;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\Capacity;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalSalesRepositoriesTest extends KernelTestCase
{
    private const string NOW = '2026-10-24T12:00:00.123456+00:00';

    private SeatStockRepositoryInterface $stocks;
    private OrderRepositoryInterface $orders;

    protected function setUp(): void
    {
        $this->stocks = self::getContainer()->get(SeatStockRepositoryInterface::class);
        $this->orders = self::getContainer()->get(OrderRepositoryInterface::class);
    }

    public function testNewSeatStockHasEveryCapacityAvailable(): void
    {
        $eventId = $this->stock(5);

        self::assertSame(5, $this->stocks->available($eventId));
    }

    public function testCreatingAnExistingSeatStockReplacesItsCapacityOnly(): void
    {
        $eventId = $this->stock(5);
        $other = $this->stock(7);
        $this->stocks->reserve($eventId, Quantity::of(1));

        $this->stocks->create($eventId, Capacity::of(12));

        self::assertSame(11, $this->stocks->available($eventId));
        self::assertSame(7, $this->stocks->available($other));
    }

    public function testReserveReturnsTheSeatsTakenAfterIt(): void
    {
        $eventId = $this->stock(5);

        self::assertSame(2, $this->stocks->reserve($eventId, Quantity::of(2)));
        self::assertSame(5, $this->stocks->reserve($eventId, Quantity::of(3)));
        self::assertSame(0, $this->stocks->available($eventId));
    }

    public function testReserveBeyondCapacityTakesNothing(): void
    {
        $eventId = $this->stock(5);
        $this->stocks->reserve($eventId, Quantity::of(4));

        self::assertNull($this->stocks->reserve($eventId, Quantity::of(2)));
        self::assertSame(1, $this->stocks->available($eventId));
    }

    public function testReserveWithoutSeatStockTakesNothing(): void
    {
        self::assertNull($this->stocks->reserve(EventId::generate(), Quantity::of(1)));
    }

    public function testReserveOnlyTouchesItsEvent(): void
    {
        $eventId = $this->stock(5);
        $otherId = $this->stock(5);

        $this->stocks->reserve($eventId, Quantity::of(3));

        self::assertSame(5, $this->stocks->available($otherId));
    }

    public function testReleaseReturnsTheSeatsTakenAfterIt(): void
    {
        $eventId = $this->stock(5);
        $this->stocks->reserve($eventId, Quantity::of(4));

        self::assertSame(1, $this->stocks->release($eventId, Quantity::of(3)));
        self::assertSame(4, $this->stocks->available($eventId));
        self::assertSame(0, $this->stocks->release($eventId, Quantity::of(1)));
    }

    public function testReleaseWithoutSeatStockFails(): void
    {
        $eventId = EventId::generate();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Event "%s" has no seat stock.', $eventId->toString()));

        $this->stocks->release($eventId, Quantity::of(1));
    }

    public function testAvailabilityWithoutSeatStockFails(): void
    {
        $this->expectException(EventNotOnSaleException::class);

        $this->stocks->available(EventId::generate());
    }

    public function testOrderRoundTripPreservesEverything(): void
    {
        $eventId = $this->stock(10);
        $full = new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of(2)), Money::EUR(2550));
        $reduced = new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of(1)), Money::EUR(5_000_000_001));
        $order = Order::place(
            OrderId::generate(),
            $eventId,
            EmailAddress::fromString('buyer@example.com'),
            [$full, $reduced],
            CommissionRate::fromBasisPoints(1234),
            new \DateTimeImmutable('2026-12-31T23:30:15.123456+05:00'),
            new \DateTimeImmutable(self::NOW),
        );

        $this->orders->save($order);
        $loaded = $this->orders->get($order->id());

        self::assertTrue($order->id()->equals($loaded->id()));
        self::assertTrue($eventId->equals($loaded->eventId()));
        self::assertSame('buyer@example.com', $loaded->buyerEmail()->toString());
        self::assertCount(2, $loaded->lines());
        self::assertTrue($full->equals($loaded->lines()[0]));
        self::assertTrue($reduced->equals($loaded->lines()[1]));
        self::assertSame('2026-12-31T18:30:15.123456+00:00', $loaded->eventStartsAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-10-24T12:15:00.123456+00:00', $loaded->expiresAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertTrue($order->amounts()->equals($loaded->amounts()));
        self::assertSame(OrderStatus::Pending, $loaded->status());
    }

    public function testSavingAgainUpdatesTheStatusOnly(): void
    {
        $order = $this->order($this->stock(10), self::NOW);
        $this->orders->save($order);

        $order->pay(PaymentOutcome::Accepted, new \DateTimeImmutable(self::NOW));
        $this->orders->save($order);

        $loaded = $this->orders->get($order->id());
        self::assertSame(OrderStatus::Paid, $loaded->status());
        self::assertCount(1, $loaded->lines());
        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM sales_order_line WHERE order_id = ?', [$order->id()->toString()]));
    }

    public function testGetForUpdateLoadsTheOrder(): void
    {
        $order = $this->order($this->stock(10), self::NOW);
        $this->orders->save($order);

        $loaded = $this->orders->getForUpdate($order->id());

        self::assertTrue($order->id()->equals($loaded->id()));
        self::assertTrue($order->lines()[0]->equals($loaded->lines()[0]));
        self::assertSame(OrderStatus::Pending, $loaded->status());
    }

    /**
     * Another connection must see the lock: this test commits its rows (outside the DAMA transaction) and deletes
     * them. Its order is due in 2099 so that no scheduler test running in parallel expires it meanwhile.
     */
    #[SkipDatabaseRollback]
    public function testGetForUpdateLocksTheOrderRowUntilTheTransactionEnds(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $eventId = $this->stock(10);
        $order = $this->order($eventId, '2099-01-01T00:00:00+00:00');
        $this->orders->save($order);
        $other = DriverManager::getConnection($connection->getParams());

        try {
            $connection->beginTransaction();
            $this->orders->getForUpdate($order->id());

            try {
                $other->fetchOne('SELECT id FROM sales_order WHERE id = ? FOR UPDATE NOWAIT', [$order->id()->toString()]);
                self::fail('The order row must be locked by the first connection.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }

            $connection->rollBack();
            self::assertSame($order->id()->toString(), $other->fetchOne('SELECT id FROM sales_order WHERE id = ? FOR UPDATE NOWAIT', [$order->id()->toString()]));
        } finally {
            $other->close();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->executeStatement('DELETE FROM sales_order_line WHERE order_id = ?', [$order->id()->toString()]);
            $connection->executeStatement('DELETE FROM sales_order WHERE id = ?', [$order->id()->toString()]);
            $connection->executeStatement('DELETE FROM seat_stock WHERE event_id = ?', [$eventId->toString()]);
        }
    }

    public function testGetUnknownOrderFails(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->orders->get(OrderId::generate());
    }

    public function testGetForUpdateUnknownOrderFails(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->orders->getForUpdate(OrderId::generate());
    }

    public function testOverduePendingIdsListsPendingOrdersPastTheirDeadline(): void
    {
        $eventId = $this->stock(10);
        $late = $this->order($eventId, '2026-10-24T11:40:00+00:00');
        $due = $this->order($eventId, '2026-10-24T11:45:00+00:00');
        $early = $this->order($eventId, '2026-10-24T11:40:00+00:00');
        $notYet = $this->order($eventId, '2026-10-24T11:45:00.000001+00:00');
        $paid = $this->order($eventId, '2026-10-24T11:30:00+00:00');
        $paid->pay(PaymentOutcome::Accepted, new \DateTimeImmutable('2026-10-24T11:31:00+00:00'));
        foreach ([$due, $late, $early, $notYet, $paid] as $order) {
            $this->orders->save($order);
        }
        $sameDeadline = [$late->id()->toString(), $early->id()->toString()];
        sort($sameDeadline);

        $ids = array_map(static fn(OrderId $id): string => $id->toString(), $this->orders->overduePendingIds(new \DateTimeImmutable('2026-10-24T13:00:00+01:00')));

        $mine = array_map(static fn(Order $order): string => $order->id()->toString(), [$late, $due, $early, $notYet, $paid]);
        self::assertSame([...$sameDeadline, $due->id()->toString()], array_values(array_intersect($ids, $mine)));
    }

    private function stock(int $capacity): EventId
    {
        $eventId = EventId::generate();
        $this->stocks->create($eventId, Capacity::of($capacity));

        return $eventId;
    }

    private function order(EventId $eventId, string $placedAt): Order
    {
        return Order::place(
            OrderId::generate(),
            $eventId,
            EmailAddress::fromString('buyer@example.com'),
            [new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of(1)), Money::EUR(1000))],
            CommissionRate::fromBasisPoints(1000),
            new \DateTimeImmutable('2026-12-01T20:00:00+00:00'),
            new \DateTimeImmutable($placedAt),
        );
    }
}
