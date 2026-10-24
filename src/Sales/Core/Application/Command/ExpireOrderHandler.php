<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Shared\Identity\OrderId;
use Psr\Clock\ClockInterface;

/**
 * Expiring an order that is not pending or not due yet changes nothing: the seats are released only when the
 * order actually became expired.
 */
final readonly class ExpireOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private OrderSeatsReleaser $seatsReleaser,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireOrder $command): void
    {
        $order = $this->orders->getForUpdate(OrderId::fromString($command->orderId));
        $before = $order->status();
        $order->expire($this->clock->now());

        if ($before === $order->status()) {
            return;
        }

        $this->orders->save($order);
        $this->seatsReleaser->release($order);
    }
}
