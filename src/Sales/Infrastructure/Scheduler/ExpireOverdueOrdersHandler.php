<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Scheduler;

use App\Sales\Core\Application\Command\ExpireOrder;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Shared\Bus\CommandBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Expires each overdue order in its own command, hence its own transaction.
 */
#[AsMessageHandler(bus: 'async.bus')]
final readonly class ExpireOverdueOrdersHandler
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private CommandBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireOverdueOrders $message): void
    {
        foreach ($this->orders->overduePendingIds($this->clock->now()) as $orderId) {
            $this->commandBus->dispatch(new ExpireOrder($orderId->toString()));
        }
    }
}
