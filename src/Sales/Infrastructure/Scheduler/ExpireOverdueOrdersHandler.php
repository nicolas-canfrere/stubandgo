<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Scheduler;

use App\Sales\Core\Application\Command\ExpireOrder;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Shared\Bus\CommandBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Expires each overdue order in its own command, hence its own transaction. A failing expiration is logged and
 * skipped: it must not hold back the other overdue orders, and the next run retries it.
 */
#[AsMessageHandler(bus: 'async.bus')]
final readonly class ExpireOverdueOrdersHandler
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private CommandBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExpireOverdueOrders $message): void
    {
        foreach ($this->orders->overduePendingIds($this->clock->now()) as $orderId) {
            try {
                $this->commandBus->dispatch(new ExpireOrder($orderId->toString()));
            } catch (\Throwable $exception) {
                $this->logger->error('Could not expire overdue order {orderId}.', [
                    'orderId' => $orderId->toString(),
                    'exception' => $exception,
                ]);
            }
        }
    }
}
