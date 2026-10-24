<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\EmptyOrderException;
use App\Sales\Core\Domain\Exception\OrderNotPendingException;
use App\Sales\Core\Domain\Order;
use App\Sales\Core\Domain\OrderStatus;
use App\Sales\Core\Domain\PaymentOutcome;
use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\OrderAmounts;
use App\Sales\Core\Domain\ValueObject\PricedOrderLine;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private const string NOW = '2030-01-01T10:00:00+00:00';
    private const string EVENT_START = '2030-06-01T20:00:00+00:00';

    public function testPlaceExposesItsState(): void
    {
        $id = OrderId::generate();
        $eventId = EventId::generate();
        $email = EmailAddress::fromString('buyer@example.com');
        $lines = [self::priced(2, 1000), self::priced(1, 500)];

        $order = Order::place(
            $id,
            $eventId,
            $email,
            $lines,
            CommissionRate::fromBasisPoints(1000),
            new \DateTimeImmutable('2030-06-01T22:00:00+02:00'),
            new \DateTimeImmutable('2030-01-01T11:00:00+01:00'),
        );

        self::assertSame($id, $order->id());
        self::assertSame($eventId, $order->eventId());
        self::assertSame($email, $order->buyerEmail());
        self::assertSame($lines, $order->lines());
        self::assertSame(OrderStatus::Pending, $order->status());
        self::assertSame('2030-06-01T20:00:00+00:00', $order->eventStartsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $order->eventStartsAt()->getTimezone()->getName());
        self::assertSame('2030-01-01T10:15:00+00:00', $order->expiresAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $order->expiresAt()->getTimezone()->getName());
        self::assertTrue(Money::EUR(2500)->equals($order->amounts()->total()));
        self::assertTrue(Money::EUR(250)->equals($order->amounts()->commission()));
        self::assertTrue(Money::EUR(2250)->equals($order->amounts()->net()));
        self::assertSame(3, $order->totalQuantity()->toInt());
    }

    public function testPlaceRejectsEmptyLines(): void
    {
        $this->expectException(EmptyOrderException::class);

        Order::place(OrderId::generate(), EventId::generate(), self::email(), [], CommissionRate::fromBasisPoints(0), self::at(self::EVENT_START), self::at(self::NOW));
    }

    public function testCommissionIsRoundedHalfUp(): void
    {
        $down = self::placeTotal(1001, 250);
        $up = self::placeTotal(1020, 250);

        self::assertTrue(Money::EUR(25)->equals($down->amounts()->commission()));
        self::assertTrue(Money::EUR(976)->equals($down->amounts()->net()));
        self::assertTrue(Money::EUR(26)->equals($up->amounts()->commission()));
        self::assertTrue(Money::EUR(994)->equals($up->amounts()->net()));
    }

    public function testFreeOrderIsPaidAtPlacement(): void
    {
        $order = self::placeTotal(0, 250);

        self::assertSame(OrderStatus::Paid, $order->status());
        self::assertTrue($order->amounts()->total()->isZero());
        self::assertFalse($order->releasesSeats());
    }

    public function testPaidOrderTotalsAreFrozenAtPlacement(): void
    {
        $order = self::placeTotal(5000, 1000);
        $before = $order->amounts();

        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:05:00+00:00'));

        self::assertSame(OrderStatus::Paid, $order->status());
        self::assertSame($before, $order->amounts());
        self::assertTrue(Money::EUR(500)->equals($order->amounts()->commission()));
    }

    public function testOrderExpiresAfter15Minutes(): void
    {
        $order = self::placeTotal(1000, 0);

        self::assertTrue($order->isPayableAt(self::at('2030-01-01T10:14:59+00:00')));
        self::assertFalse($order->isPayableAt(self::at('2030-01-01T10:15:00+00:00')));

        $order->expire(self::at('2030-01-01T10:14:59+00:00'));
        self::assertSame(OrderStatus::Pending, $order->status());

        $order->expire(self::at('2030-01-01T10:15:00+00:00'));
        self::assertSame(OrderStatus::Expired, $order->status());
        self::assertTrue($order->releasesSeats());
    }

    public function testExpireIgnoresNonPendingOrders(): void
    {
        $order = self::placeTotal(1000, 0);
        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:01:00+00:00'));

        $order->expire(self::at('2030-01-01T11:00:00+00:00'));

        self::assertSame(OrderStatus::Paid, $order->status());
    }

    public function testPayAfterExpiryMarksExpired(): void
    {
        $order = self::placeTotal(1000, 0);

        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:15:00+00:00'));

        self::assertSame(OrderStatus::Expired, $order->status());
    }

    public function testPayAfterEventStartMarksExpired(): void
    {
        $order = Order::place(
            OrderId::generate(),
            EventId::generate(),
            self::email(),
            [self::priced(1, 1000)],
            CommissionRate::fromBasisPoints(0),
            self::at('2030-01-01T10:10:00+00:00'),
            self::at(self::NOW),
        );

        self::assertTrue($order->isPayableAt(self::at('2030-01-01T10:09:59+00:00')));
        self::assertFalse($order->isPayableAt(self::at('2030-01-01T10:10:00+00:00')));

        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:10:00+00:00'));

        self::assertSame(OrderStatus::Expired, $order->status());
    }

    public function testPayRightBeforeExpiryIsAccepted(): void
    {
        $order = self::placeTotal(1000, 0);

        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:14:59+00:00'));

        self::assertSame(OrderStatus::Paid, $order->status());
    }

    public function testDeclinedPaymentMarksFailed(): void
    {
        $order = self::placeTotal(1000, 0);

        $order->pay(PaymentOutcome::Declined, self::at('2030-01-01T10:01:00+00:00'));

        self::assertSame(OrderStatus::Failed, $order->status());
        self::assertTrue($order->releasesSeats());
    }

    public function testDeclinedPaymentAfterExpiryMarksExpired(): void
    {
        $order = self::placeTotal(1000, 0);

        $order->pay(PaymentOutcome::Declined, self::at('2030-01-01T10:20:00+00:00'));

        self::assertSame(OrderStatus::Expired, $order->status());
    }

    public function testPayingNonPendingOrderThrowsAndChangesNothing(): void
    {
        $order = self::placeTotal(1000, 0);
        $order->pay(PaymentOutcome::Declined, self::at('2030-01-01T10:01:00+00:00'));

        try {
            $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:02:00+00:00'));
            self::fail('Paying a non pending order must throw.');
        } catch (OrderNotPendingException) {
            self::assertSame(OrderStatus::Failed, $order->status());
        }
    }

    public function testPayingExpiredOrderThrows(): void
    {
        $order = self::placeTotal(1000, 0);
        $order->expire(self::at('2030-01-01T10:15:00+00:00'));

        $this->expectException(OrderNotPendingException::class);

        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:16:00+00:00'));
    }

    public function testPendingOrderDoesNotReleaseSeats(): void
    {
        self::assertFalse(self::placeTotal(1000, 0)->releasesSeats());
    }

    public function testPaidOrderDoesNotReleaseSeats(): void
    {
        $order = self::placeTotal(1000, 0);
        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:01:00+00:00'));

        self::assertFalse($order->releasesSeats());
    }

    public function testToOrderPaidCarriesPlainLines(): void
    {
        $first = self::priced(2, 1000);
        $second = self::priced(1, 500);
        $id = OrderId::generate();
        $eventId = EventId::generate();
        $email = self::email();
        $order = Order::place($id, $eventId, $email, [$first, $second], CommissionRate::fromBasisPoints(0), self::at(self::EVENT_START), self::at(self::NOW));
        $order->pay(PaymentOutcome::Accepted, self::at('2030-01-01T10:01:00+00:00'));

        $event = $order->toOrderPaid();

        self::assertSame($id, $event->orderId);
        self::assertSame($eventId, $event->eventId);
        self::assertSame($email, $event->buyerEmail);
        self::assertSame([$first->line(), $second->line()], $event->lines);
    }

    public function testToOrderPaidOnNonPaidOrderThrows(): void
    {
        $this->expectException(\LogicException::class);

        self::placeTotal(1000, 0)->toOrderPaid();
    }

    public function testReconstituteRestoresPersistedState(): void
    {
        $id = OrderId::generate();
        $eventId = EventId::generate();
        $email = self::email();
        $lines = [self::priced(1, 1000)];
        $amounts = OrderAmounts::of(Money::EUR(1000), Money::EUR(100), Money::EUR(900));

        $order = Order::reconstitute(
            $id,
            $eventId,
            $email,
            $lines,
            self::at('2030-06-01T22:00:00+02:00'),
            $amounts,
            OrderStatus::Failed,
            self::at('2030-01-01T11:15:00+01:00'),
        );

        self::assertSame($id, $order->id());
        self::assertSame($eventId, $order->eventId());
        self::assertSame($email, $order->buyerEmail());
        self::assertSame($lines, $order->lines());
        self::assertSame($amounts, $order->amounts());
        self::assertSame(OrderStatus::Failed, $order->status());
        self::assertSame('2030-06-01T20:00:00+00:00', $order->eventStartsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $order->eventStartsAt()->getTimezone()->getName());
        self::assertSame('2030-01-01T10:15:00+00:00', $order->expiresAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $order->expiresAt()->getTimezone()->getName());
    }

    #[DataProvider('statusValues')]
    public function testOrderStatusPersistedValues(string $value, OrderStatus $status): void
    {
        self::assertSame($status, OrderStatus::from($value));
        self::assertSame($value, $status->value);
    }

    /**
     * @return iterable<string, array{string, OrderStatus}>
     */
    public static function statusValues(): iterable
    {
        yield 'pending' => ['pending', OrderStatus::Pending];
        yield 'paid' => ['paid', OrderStatus::Paid];
        yield 'failed' => ['failed', OrderStatus::Failed];
        yield 'expired' => ['expired', OrderStatus::Expired];
    }

    private static function placeTotal(int $totalCents, int $basisPoints): Order
    {
        return Order::place(
            OrderId::generate(),
            EventId::generate(),
            self::email(),
            [self::priced(1, $totalCents)],
            CommissionRate::fromBasisPoints($basisPoints),
            self::at(self::EVENT_START),
            self::at(self::NOW),
        );
    }

    private static function priced(int $quantity, int $unitCents): PricedOrderLine
    {
        return new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of($quantity)), Money::EUR($unitCents));
    }

    private static function email(): EmailAddress
    {
        return EmailAddress::fromString('buyer@example.com');
    }

    private static function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }
}
