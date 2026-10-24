<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure\Scheduler;

use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Sales\Core\Application\Query\OrderStatusView;
use App\Sales\Infrastructure\Scheduler\ExpireOverdueOrders;
use App\Sales\Infrastructure\Scheduler\ExpireOverdueOrdersHandler;
use App\Tests\Sales\AbstractSalesTestCase;
use Doctrine\DBAL\Connection;

final class ExpireOverdueOrdersHandlerTest extends AbstractSalesTestCase
{
    public function testOverdueOrdersAreExpiredBySchedulerHandler(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $first = $this->placeSingleLineOrder($eventId, $tier, 2);
        $second = $this->placeSingleLineOrder($eventId, $tier, 3);
        $this->clock->modify('+10 minutes');
        $recent = $this->placeSingleLineOrder($eventId, $tier, 1);
        $this->clock->modify('+6 minutes');

        self::getContainer()->get(ExpireOverdueOrdersHandler::class)(new ExpireOverdueOrders());

        self::assertSame('expired', $this->statusOf($first));
        self::assertSame('expired', $this->statusOf($second));
        self::assertSame('pending', $this->statusOf($recent));
        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT taken FROM seat_stock WHERE event_id = ?', [$eventId]));
    }

    private function statusOf(string $orderId): string
    {
        $view = $this->ask(new GetOrderStatus($orderId));
        self::assertInstanceOf(OrderStatusView::class, $view);

        return $view->status;
    }
}
