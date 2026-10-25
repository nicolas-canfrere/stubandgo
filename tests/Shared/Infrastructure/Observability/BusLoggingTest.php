<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Sales\Core\Application\Command\PayOrder;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Sales\Core\Domain\Exception\SoldOutException;
use App\Shared\Event\OrderPaid;
use App\Shared\Identity\OrderId;
use App\Tests\Sales\AbstractSalesTestCase;
use App\Tests\Shared\Infrastructure\Outbox\Fixtures\StoreOutboxMessage;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;

/**
 * Command and domain event logs, as wired on the real buses.
 */
final class BusLoggingTest extends AbstractSalesTestCase
{
    use LogRecordsTrait;

    public function testHandledCommandIsLoggedAsInfo(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale();
        self::logHandler()->clear();

        $this->placeSingleLineOrder($eventId, $tier);

        $records = self::logRecords('command.handled');
        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame(['command' => PlaceOrder::class, 'duration_ms' => 0], $records[0]->context);
    }

    public function testRejectedCommandIsLoggedAsWarningWithErrorCode(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 1);

        try {
            $this->placeSingleLineOrder($eventId, $tier, 2);
            self::fail('The order should have been rejected.');
        } catch (SoldOutException) {
        }

        $records = self::logRecords('command.rejected');
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame(['command' => PlaceOrder::class, 'error_code' => 'sold_out'], $records[0]->context);
    }

    public function testUnexpectedExceptionIsLoggedAsError(): void
    {
        try {
            $this->command(new StoreOutboxMessage('a', fail: true));
            self::fail('The handler exception should have been rethrown.');
        } catch (\RuntimeException) {
        }

        $records = self::logRecords('command.failed');
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame(StoreOutboxMessage::class, $records[0]->context['command'] ?? null);
        $exception = $records[0]->context['exception'] ?? null;
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('boom', $exception->getMessage());
    }

    public function testOrderPaidIsLoggedWithoutBuyerEmail(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale();
        $email = strtolower(OrderId::generate()->toString()) . '@example.com';
        $orderId = $this->placeOrder($eventId, [['priceTierId' => $tier, 'quantity' => 1]], $email);

        $this->command(new PayOrder($orderId, '4242424242424242'));

        $orderPaid = array_values(array_filter(
            self::logRecords('domain_event'),
            static fn($record): bool => OrderPaid::class === ($record->context['event'] ?? null),
        ));
        self::assertCount(1, $orderPaid);
        self::assertSame(Level::Info, $orderPaid[0]->level);
        self::assertSame(['event' => OrderPaid::class, 'order_id' => $orderId, 'event_id' => $eventId], $orderPaid[0]->context);
        // Debug records are left out: in the test kernel (debug mode) DBAL logs the parameters of its queries,
        // which the dev and prod handlers (level info, no debug kernel in prod) never write.
        $formatter = new JsonFormatter();
        foreach (self::logRecords() as $record) {
            if ($record->level->isHigherThan(Level::Debug)) {
                self::assertStringNotContainsString($email, $formatter->format($record));
            }
        }
    }
}
