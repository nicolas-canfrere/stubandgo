<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\EmptyOrderException;
use App\Sales\Core\Domain\Exception\OrderNotPendingException;
use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\OrderAmounts;
use App\Sales\Core\Domain\ValueObject\PricedOrderLine;
use App\Shared\Event\OrderPaid;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\Quantity;
use Money\Money;

final class Order
{
    private const string PAYMENT_DELAY = 'PT15M';

    /**
     * @param list<PricedOrderLine> $lines
     */
    private function __construct(
        private readonly OrderId $id,
        private readonly EventId $eventId,
        private readonly EmailAddress $buyerEmail,
        private readonly array $lines,
        private readonly \DateTimeImmutable $eventStartsAt,
        private readonly OrderAmounts $amounts,
        private OrderStatus $status,
        private readonly \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * Places an order: amounts are computed once and frozen. A free order is paid right away.
     *
     * @param list<PricedOrderLine> $lines
     *
     * @throws EmptyOrderException
     */
    public static function place(
        OrderId $id,
        EventId $eventId,
        EmailAddress $buyerEmail,
        array $lines,
        CommissionRate $commissionRate,
        \DateTimeImmutable $eventStartsAt,
        \DateTimeImmutable $now,
    ): self {
        if ([] === $lines) {
            throw EmptyOrderException::create();
        }

        $amounts = OrderAmounts::compute(
            array_reduce(
                $lines,
                static fn(Money $total, PricedOrderLine $line): Money => $total->add($line->subtotal()),
                Money::EUR(0),
            ),
            $commissionRate,
        );

        return new self(
            $id,
            $eventId,
            $buyerEmail,
            $lines,
            self::utc($eventStartsAt),
            $amounts,
            $amounts->total()->isZero() ? OrderStatus::Paid : OrderStatus::Pending,
            self::utc($now)->add(new \DateInterval(self::PAYMENT_DELAY)),
        );
    }

    /**
     * Rebuilds a persisted order: the placement checks already passed when it was first created.
     *
     * @param list<PricedOrderLine> $lines
     */
    public static function reconstitute(
        OrderId $id,
        EventId $eventId,
        EmailAddress $buyerEmail,
        array $lines,
        \DateTimeImmutable $eventStartsAt,
        OrderAmounts $amounts,
        OrderStatus $status,
        \DateTimeImmutable $expiresAt,
    ): self {
        return new self($id, $eventId, $buyerEmail, $lines, self::utc($eventStartsAt), $amounts, $status, self::utc($expiresAt));
    }

    public function isPayableAt(\DateTimeImmutable $now): bool
    {
        return $now < $this->expiresAt && $now < $this->eventStartsAt;
    }

    /**
     * Records the payment attempt. A late attempt expires the order and a declined one fails it: both are
     * persisted transitions, not errors.
     *
     * @throws OrderNotPendingException
     */
    public function pay(PaymentOutcome $outcome, \DateTimeImmutable $now): void
    {
        if (OrderStatus::Pending !== $this->status) {
            throw OrderNotPendingException::withId($this->id);
        }

        if (!$this->isPayableAt($now)) {
            $this->status = OrderStatus::Expired;

            return;
        }

        $this->status = PaymentOutcome::Accepted === $outcome ? OrderStatus::Paid : OrderStatus::Failed;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        if (OrderStatus::Pending === $this->status && $now >= $this->expiresAt) {
            $this->status = OrderStatus::Expired;
        }
    }

    /**
     * Whether the seats held by this order go back to the stock: it ended without being paid.
     */
    public function releasesSeats(): bool
    {
        return OrderStatus::Failed === $this->status || OrderStatus::Expired === $this->status;
    }

    public function totalQuantity(): Quantity
    {
        return Quantity::of(array_sum(array_map(
            static fn(PricedOrderLine $line): int => $line->line()->quantity->toInt(),
            $this->lines,
        )));
    }

    public function toOrderPaid(): OrderPaid
    {
        if (OrderStatus::Paid !== $this->status) {
            throw new \LogicException('Only a paid order can be announced as paid.');
        }

        return new OrderPaid(
            $this->id,
            $this->eventId,
            $this->buyerEmail,
            array_map(static fn(PricedOrderLine $line) => $line->line(), $this->lines),
        );
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function buyerEmail(): EmailAddress
    {
        return $this->buyerEmail;
    }

    /**
     * @return list<PricedOrderLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function eventStartsAt(): \DateTimeImmutable
    {
        return $this->eventStartsAt;
    }

    public function amounts(): OrderAmounts
    {
        return $this->amounts;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    private static function utc(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
