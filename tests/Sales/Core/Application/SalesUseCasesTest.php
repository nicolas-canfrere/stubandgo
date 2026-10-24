<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Application;

use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Sales\Core\Application\Command\ExpireOrder;
use App\Sales\Core\Application\Command\PayOrder;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Sales\Core\Application\Query\EventAvailabilityView;
use App\Sales\Core\Application\Query\GetEventAvailability;
use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Sales\Core\Application\Query\OrderLineView;
use App\Sales\Core\Application\Query\OrderStatusView;
use App\Sales\Core\Domain\Exception\DuplicatePriceTierException;
use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\Exception\OrderNotFoundException;
use App\Sales\Core\Domain\Exception\OrderNotPendingException;
use App\Sales\Core\Domain\Exception\OrderTooLargeException;
use App\Sales\Core\Domain\Exception\SalesClosedException;
use App\Sales\Core\Domain\Exception\SoldOutException;
use App\Sales\Core\Domain\Exception\UnknownPriceTierException;
use App\Shared\Event\EventSeatsAllReleased;
use App\Shared\Event\EventSeatsFirstTaken;
use App\Shared\Event\OrderPaid;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\OrderLine;
use App\Tests\Sales\AbstractSalesTestCase;
use App\Tests\Sales\Fixtures\RecordingPaymentGateway;
use App\Tests\Sales\Fixtures\SalesEventRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

final class SalesUseCasesTest extends AbstractSalesTestCase
{
    private const string ACCEPTED_CARD = '4242424242424242';
    private const string DECLINED_CARD = '4000000000000002';

    public function testPlacingOrderReservesSeatsAndReturnsPendingStatus(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$full, $reduced]] = $this->eventOnSale(capacity: 10, pricesCents: [2500, 1000]);

        $orderId = $this->placeOrder($eventId, [
            ['priceTierId' => $full, 'quantity' => 2],
            ['priceTierId' => $reduced, 'quantity' => 1],
        ]);

        self::assertEquals(
            new OrderStatusView($orderId, 'pending', $eventId, [
                new OrderLineView($full, 2, 2500),
                new OrderLineView($reduced, 1, 1000),
            ], 6000),
            $this->orderStatus($orderId),
        );
        self::assertEquals(new EventAvailabilityView($eventId, 7), $this->ask(new GetEventAvailability($eventId)));
        self::assertTrue(self::getContainer()->get(EventRepositoryInterface::class)->get(EventId::fromString($eventId))->isLocked());
        self::assertSame([], $this->recorder()->recorded(OrderPaid::class));
    }

    public function testPublishedEventOffersItsWholeCapacity(): void
    {
        ['eventId' => $eventId] = $this->eventOnSale(capacity: 42);

        self::assertSame(42, $this->remaining($eventId));
    }

    public function testSoldOutOrderReservesNothing(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 2);
        $orderId = OrderId::generate()->toString();

        try {
            $this->command(new PlaceOrder($orderId, $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 3]]));
            self::fail('A sold out order must be rejected.');
        } catch (SoldOutException) {
        }

        self::assertSame(2, $this->remaining($eventId));
        $this->assertOrderDoesNotExist($orderId);
        self::assertSame([], $this->recorder()->recorded(EventSeatsFirstTaken::class));
    }

    public function testOrderTakingTheLastSeatsIsAccepted(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 3);

        $this->placeSingleLineOrder($eventId, $tier, 3);

        self::assertSame(0, $this->remaining($eventId));
    }

    public function testPriceTierOfAnotherEventIsRejected(): void
    {
        ['eventId' => $eventId] = $this->eventOnSale(capacity: 10);
        ['priceTierIds' => [$otherTier]] = $this->eventOnSale(capacity: 10);

        try {
            $this->placeSingleLineOrder($eventId, $otherTier);
            self::fail('A price tier of another event must be rejected.');
        } catch (UnknownPriceTierException) {
        }

        self::assertSame(10, $this->remaining($eventId));
    }

    public function testOrderOnUnknownEventIsRejected(): void
    {
        $this->expectException(EventNotOnSaleException::class);

        $this->placeSingleLineOrder(EventId::generate()->toString(), PriceTierId::generate()->toString());
    }

    public function testOrderOnUnpublishedEventIsRejected(): void
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], self::EVENT_START, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]);

        $this->expectException(EventNotOnSaleException::class);

        $this->placeSingleLineOrder($eventId, $tier);
    }

    public function testOrderAfterEventStartIsRejected(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $this->clock->modify(self::EVENT_START);

        try {
            $this->placeSingleLineOrder($eventId, $tier);
            self::fail('Sales must be closed once the event started.');
        } catch (SalesClosedException) {
        }

        self::assertSame(10, $this->remaining($eventId));
    }

    public function testOrderOfMoreThanTenTicketsIsRejected(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$full, $reduced]] = $this->eventOnSale(capacity: 50, pricesCents: [2500, 1000]);

        try {
            $this->placeOrder($eventId, [['priceTierId' => $full, 'quantity' => 6], ['priceTierId' => $reduced, 'quantity' => 5]]);
            self::fail('An order of more than ten tickets must be rejected.');
        } catch (OrderTooLargeException) {
        }

        self::assertSame(50, $this->remaining($eventId));
    }

    public function testOrderRepeatingAPriceTierIsRejected(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);

        $this->expectException(DuplicatePriceTierException::class);

        $this->placeOrder($eventId, [['priceTierId' => $tier, 'quantity' => 1], ['priceTierId' => $tier, 'quantity' => 1]]);
    }

    public function testOrderWithAnInvalidEmailIsRejected(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);

        try {
            $this->placeOrder($eventId, [['priceTierId' => $tier, 'quantity' => 1]], 'not-an-email');
            self::fail('An invalid email must be rejected.');
        } catch (InvalidValueException) {
        }

        self::assertSame(10, $this->remaining($eventId));
    }

    public function testFirstReservationPublishesEventSeatsFirstTakenOnce(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);

        $this->placeSingleLineOrder($eventId, $tier, 2);
        $this->placeSingleLineOrder($eventId, $tier, 3);

        self::assertEquals([new EventSeatsFirstTaken(EventId::fromString($eventId))], $this->recorder()->recorded(EventSeatsFirstTaken::class));
    }

    public function testFreeOrderPublishesOrderPaid(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10, pricesCents: [0]);

        $orderId = $this->placeOrder($eventId, [['priceTierId' => $tier, 'quantity' => 2]], 'Buyer@Example.com');

        self::assertSame('paid', $this->orderStatus($orderId)->status);
        self::assertSame(0, $this->orderStatus($orderId)->totalCents);
        self::assertSame(8, $this->remaining($eventId));
        $this->assertOrderPaidRecorded($orderId, $eventId, 'buyer@example.com', [[$tier, 2]]);
        self::assertSame([], $this->gateway()->charges());
    }

    public function testAcceptedPaymentPublishesOrderPaid(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10, pricesCents: [2500]);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 2);

        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        self::assertSame('paid', $this->orderStatus($orderId)->status);
        self::assertSame(8, $this->remaining($eventId));
        self::assertSame([['orderId' => $orderId, 'amountCents' => '5000']], $this->gateway()->charges());
        $this->assertOrderPaidRecorded($orderId, $eventId, 'buyer@example.com', [[$tier, 2]]);
        self::assertSame([], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testPaymentJustBeforeTheDeadlineIsCharged(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier);
        $this->clock->modify('+14 minutes 59 seconds');

        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        self::assertSame('paid', $this->orderStatus($orderId)->status);
    }

    #[DataProvider('declinedCards')]
    public function testDeclinedPaymentReleasesSeats(string $cardNumber): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 3);

        $this->command(new PayOrder($orderId, $cardNumber));

        self::assertSame('failed', $this->orderStatus($orderId)->status);
        self::assertSame(10, $this->remaining($eventId));
        self::assertCount(1, $this->gateway()->charges());
        self::assertSame([], $this->recorder()->recorded(OrderPaid::class));
        self::assertFalse(self::getContainer()->get(EventRepositoryInterface::class)->get(EventId::fromString($eventId))->isLocked());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declinedCards(): iterable
    {
        yield 'declined test card' => [self::DECLINED_CARD];
        yield 'any other card' => ['5555 5555 5555 4444'];
    }

    public function testLatePaymentExpiresOrderAndReleasesSeats(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 2);
        $this->clock->modify('+16 minutes');

        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        self::assertSame('expired', $this->orderStatus($orderId)->status);
        self::assertSame(10, $this->remaining($eventId));
        self::assertSame([], $this->gateway()->charges());
        self::assertSame([], $this->recorder()->recorded(OrderPaid::class));
        self::assertEquals([new EventSeatsAllReleased(EventId::fromString($eventId))], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testLastReleasePublishesEventSeatsAllReleased(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $first = $this->placeSingleLineOrder($eventId, $tier, 2);
        $second = $this->placeSingleLineOrder($eventId, $tier, 1);

        $this->command(new PayOrder($first, self::DECLINED_CARD));
        self::assertSame([], $this->recorder()->recorded(EventSeatsAllReleased::class));

        $this->command(new PayOrder($second, self::DECLINED_CARD));
        self::assertEquals([new EventSeatsAllReleased(EventId::fromString($eventId))], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testPayingAPaidOrderIsRejectedWithoutCharge(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier);
        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        try {
            $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));
            self::fail('A paid order cannot be paid again.');
        } catch (OrderNotPendingException) {
        }

        self::assertCount(1, $this->gateway()->charges());
        self::assertCount(1, $this->recorder()->recorded(OrderPaid::class));
        self::assertSame(9, $this->remaining($eventId));
    }

    public function testPayingAFailedOrderIsRejectedWithoutReleasingAgain(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier);
        $this->placeSingleLineOrder($eventId, $tier);
        $this->command(new PayOrder($orderId, self::DECLINED_CARD));

        try {
            $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));
            self::fail('A failed order cannot be paid again.');
        } catch (OrderNotPendingException) {
        }

        self::assertSame('failed', $this->orderStatus($orderId)->status);
        self::assertSame(9, $this->remaining($eventId));
        self::assertCount(1, $this->gateway()->charges());
    }

    public function testPayingAnUnknownOrderFails(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->command(new PayOrder(OrderId::generate()->toString(), self::ACCEPTED_CARD));
    }

    public function testPaymentWithAMalformedCardNumberChangesNothing(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier);

        try {
            $this->command(new PayOrder($orderId, 'not a card'));
            self::fail('A malformed card number must be rejected.');
        } catch (InvalidValueException $exception) {
            self::assertStringNotContainsString('not a card', $exception->getMessage());
        }

        self::assertSame('pending', $this->orderStatus($orderId)->status);
        self::assertSame([], $this->gateway()->charges());
    }

    public function testExpireOrderReleasesSeatsOfAnOverdueOrder(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 4);
        $this->clock->modify('+15 minutes');

        $this->command(new ExpireOrder($orderId));

        self::assertSame('expired', $this->orderStatus($orderId)->status);
        self::assertSame(10, $this->remaining($eventId));
        self::assertEquals([new EventSeatsAllReleased(EventId::fromString($eventId))], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testExpireOrderKeepsTheOtherSeatsTaken(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 4);
        $this->placeSingleLineOrder($eventId, $tier, 1);
        $this->clock->modify('+15 minutes');

        $this->command(new ExpireOrder($orderId));

        self::assertSame(9, $this->remaining($eventId));
        self::assertSame([], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testExpireOrderIsNoOpBeforeTheDeadline(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 4);
        $this->clock->modify('+14 minutes 59 seconds');

        $this->command(new ExpireOrder($orderId));

        self::assertSame('pending', $this->orderStatus($orderId)->status);
        self::assertSame(6, $this->remaining($eventId));
        self::assertSame([], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testExpireOrderIsNoOpOnPaidOrder(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 2);
        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));
        $this->clock->modify('+16 minutes');

        $this->command(new ExpireOrder($orderId));

        self::assertSame('paid', $this->orderStatus($orderId)->status);
        self::assertSame(8, $this->remaining($eventId));
        self::assertSame([], $this->recorder()->recorded(EventSeatsAllReleased::class));
    }

    public function testExpireOrderIsNoOpOnFailedOrder(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier, 2);
        $this->placeSingleLineOrder($eventId, $tier, 1);
        $this->command(new PayOrder($orderId, self::DECLINED_CARD));
        $this->clock->modify('+16 minutes');

        $this->command(new ExpireOrder($orderId));

        self::assertSame('failed', $this->orderStatus($orderId)->status);
        self::assertSame(9, $this->remaining($eventId));
    }

    public function testExpiringAnUnknownOrderFails(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->command(new ExpireOrder(OrderId::generate()->toString()));
    }

    public function testStatusOfAnUnknownOrderFails(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->orderStatus(OrderId::generate()->toString());
    }

    public function testAvailabilityOfAnEventWithoutSeatStockFails(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], self::EVENT_START);

        $this->expectException(EventNotOnSaleException::class);

        $this->remaining($eventId);
    }

    public function testCardNumberIsNeverPersisted(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $orderId = $this->placeSingleLineOrder($eventId, $tier);

        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        $connection = self::getContainer()->get(Connection::class);
        $columns = $connection->fetchFirstColumn(
            "SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name IN ('sales_order', 'sales_order_line', 'seat_stock') ORDER BY 1",
        );
        self::assertNotSame([], $columns);
        self::assertSame([], array_values(array_filter($columns, static fn(mixed $column): bool => \is_string($column) && str_contains($column, 'card'))));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM sales_order o WHERE o.id = ?', [$orderId]));
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM sales_order o WHERE o::text LIKE '%4242424242424242%'"));
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM sales_order_line l WHERE l::text LIKE '%4242424242424242%'"));
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM outbox_message m WHERE m::text LIKE '%4242424242424242%'"));
    }

    private function orderStatus(string $orderId): OrderStatusView
    {
        $view = $this->ask(new GetOrderStatus($orderId));

        return $view;
    }

    private function remaining(string $eventId): int
    {
        $view = $this->ask(new GetEventAvailability($eventId));
        self::assertSame($eventId, $view->eventId);

        return $view->remaining;
    }

    private function assertOrderDoesNotExist(string $orderId): void
    {
        try {
            $this->orderStatus($orderId);
            self::fail('The order must not exist.');
        } catch (OrderNotFoundException) {
        }
    }

    /**
     * @param list<array{string, int}> $lines price tier identifier and quantity of each expected line
     */
    private function assertOrderPaidRecorded(string $orderId, string $eventId, string $buyerEmail, array $lines): void
    {
        $recorded = $this->recorder()->recorded(OrderPaid::class);
        self::assertCount(1, $recorded);
        self::assertSame($orderId, $recorded[0]->orderId->toString());
        self::assertSame($eventId, $recorded[0]->eventId->toString());
        self::assertSame($buyerEmail, $recorded[0]->buyerEmail->toString());
        self::assertSame(
            $lines,
            array_map(static fn(OrderLine $line): array => [$line->priceTierId->toString(), $line->quantity->toInt()], $recorded[0]->lines),
        );
    }

    private function recorder(): SalesEventRecorder
    {
        return self::getContainer()->get(SalesEventRecorder::class);
    }

    private function gateway(): RecordingPaymentGateway
    {
        return self::getContainer()->get(RecordingPaymentGateway::class);
    }
}
