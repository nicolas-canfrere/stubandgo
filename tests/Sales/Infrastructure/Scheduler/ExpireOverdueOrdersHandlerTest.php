<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure\Scheduler;

use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Sales\Core\Application\Query\OrderStatusView;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Sales\Infrastructure\Scheduler\ExpireOverdueOrders;
use App\Sales\Infrastructure\Scheduler\ExpireOverdueOrdersHandler;
use App\Shared\Bus\CommandBusInterface;
use App\Tests\Sales\AbstractSalesTestCase;
use App\Tests\Sales\Fixtures\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LogLevel;

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

    public function testAFailingExpirationDoesNotPreventTheOthers(): void
    {
        ['eventId' => $brokenEventId, 'priceTierIds' => [$brokenTier]] = $this->eventOnSale(capacity: 10);
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);
        $broken = $this->placeSingleLineOrder($brokenEventId, $brokenTier, 2);
        $this->clock->modify('+1 second');
        $healthy = $this->placeSingleLineOrder($eventId, $tier, 3);
        $this->clock->modify('+1 second');
        $alsoHealthy = $this->placeSingleLineOrder($eventId, $tier, 1);
        $connection = self::getContainer()->get(Connection::class);
        // Stock drift: releasing the 2 seats of the broken order now violates the taken >= 0 check, on every run.
        $connection->executeStatement('UPDATE seat_stock SET taken = 0 WHERE event_id = ?', [$brokenEventId]);
        $this->clock->modify('+16 minutes');
        $logger = new RecordingLogger();
        $handler = new ExpireOverdueOrdersHandler(
            self::getContainer()->get(OrderRepositoryInterface::class),
            self::getContainer()->get(CommandBusInterface::class),
            $this->clock,
            $logger,
        );

        $handler(new ExpireOverdueOrders());

        self::assertSame('pending', $this->statusOf($broken));
        self::assertSame('expired', $this->statusOf($healthy));
        self::assertSame('expired', $this->statusOf($alsoHealthy));
        self::assertSame(0, $connection->fetchOne('SELECT taken FROM seat_stock WHERE event_id = ?', [$eventId]));
        $records = $logger->records();
        self::assertCount(1, $records);
        self::assertSame(LogLevel::ERROR, $records[0]['level']);
        self::assertSame('Could not expire overdue order {orderId}.', $records[0]['message']);
        self::assertSame(['orderId', 'exception'], array_keys($records[0]['context']));
        self::assertSame($broken, $records[0]['context']['orderId']);
        self::assertInstanceOf(DriverException::class, $records[0]['context']['exception']);
    }

    private function statusOf(string $orderId): string
    {
        $view = $this->ask(new GetOrderStatus($orderId));
        self::assertInstanceOf(OrderStatusView::class, $view);

        return $view->status;
    }
}
