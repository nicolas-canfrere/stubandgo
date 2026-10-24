<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

use App\Sales\Core\Domain\Exception\OrderNotFoundException;
use App\Sales\Core\Domain\Exception\OrderNotPendingException;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Sales\Core\Domain\OrderStatus;
use App\Sales\Core\Domain\PaymentGatewayInterface;
use App\Sales\Core\Domain\PaymentOutcome;
use App\Sales\Core\Domain\ValueObject\CardNumber;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Identity\OrderId;
use Psr\Clock\ClockInterface;

/**
 * A declined or late payment is a persisted transition (failed or expired), not an error: the caller reads the
 * resulting status afterwards.
 */
final readonly class PayOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private PaymentGatewayInterface $payments,
        private OrderSeatsReleaser $seatsReleaser,
        private EventBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrderNotFoundException
     * @throws OrderNotPendingException
     */
    public function __invoke(PayOrder $command): void
    {
        $orderId = OrderId::fromString($command->orderId);
        $cardNumber = CardNumber::fromString($command->cardNumber);
        $order = $this->orders->getForUpdate($orderId);

        if (OrderStatus::Pending !== $order->status()) {
            throw OrderNotPendingException::withId($orderId);
        }

        $now = $this->clock->now();
        $outcome = $order->isPayableAt($now)
            ? $this->payments->charge($orderId, $order->amounts()->total(), $cardNumber)
            : PaymentOutcome::Declined;
        $order->pay($outcome, $now);
        $this->orders->save($order);

        if ($order->releasesSeats()) {
            $this->seatsReleaser->release($order);

            return;
        }

        $this->eventBus->publish($order->toOrderPaid());
    }
}
