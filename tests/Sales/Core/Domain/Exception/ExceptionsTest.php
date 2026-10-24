<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain\Exception;

use App\Sales\Core\Domain\Exception\DuplicatePriceTierException;
use App\Sales\Core\Domain\Exception\EmptyOrderException;
use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\Exception\OrderNotFoundException;
use App\Sales\Core\Domain\Exception\OrderNotPendingException;
use App\Sales\Core\Domain\Exception\OrderTooLargeException;
use App\Sales\Core\Domain\Exception\SalesClosedException;
use App\Sales\Core\Domain\Exception\SoldOutException;
use App\Sales\Core\Domain\Exception\UnknownPriceTierException;
use App\Shared\Exception\AbstractConflictException;
use App\Shared\Exception\AbstractDomainException;
use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function testSoldOut(): void
    {
        $id = EventId::generate();
        $e = SoldOutException::forEvent($id);

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('sold_out', $e->errorCode());
        self::assertSame(\sprintf('Event "%s" has not enough seats left.', $id->toString()), $e->getMessage());
    }

    public function testSalesClosed(): void
    {
        $id = EventId::generate();
        $e = SalesClosedException::forEvent($id);

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('sales_closed', $e->errorCode());
        self::assertSame(\sprintf('Sales are closed for event "%s".', $id->toString()), $e->getMessage());
    }

    public function testOrderNotPending(): void
    {
        $id = OrderId::generate();
        $e = OrderNotPendingException::withId($id);

        self::assertInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertSame('order_not_pending', $e->errorCode());
        self::assertSame(\sprintf('Order "%s" is not pending.', $id->toString()), $e->getMessage());
    }

    public function testOrderNotFound(): void
    {
        $id = OrderId::generate();
        $e = OrderNotFoundException::withId($id);

        self::assertInstanceOf(AbstractNotFoundException::class, self::asThrowable($e));
        self::assertSame('order_not_found', $e->errorCode());
        self::assertSame(\sprintf('Order "%s" not found.', $id->toString()), $e->getMessage());
    }

    public function testOrderTooLarge(): void
    {
        $e = OrderTooLargeException::exceeding(10);

        self::assertNotInstanceOf(AbstractConflictException::class, self::asThrowable($e));
        self::assertInstanceOf(AbstractDomainException::class, self::asThrowable($e));
        self::assertSame('order_too_large', $e->errorCode());
        self::assertSame('An order cannot contain more than 10 tickets.', $e->getMessage());
    }

    public function testEmptyOrder(): void
    {
        $e = EmptyOrderException::create();

        self::assertInstanceOf(AbstractDomainException::class, self::asThrowable($e));
        self::assertSame('empty_order', $e->errorCode());
        self::assertSame('An order must contain at least one line.', $e->getMessage());
    }

    public function testDuplicatePriceTier(): void
    {
        $id = PriceTierId::generate();
        $e = DuplicatePriceTierException::withId($id);

        self::assertInstanceOf(AbstractDomainException::class, self::asThrowable($e));
        self::assertSame('duplicate_price_tier', $e->errorCode());
        self::assertSame(\sprintf('Price tier "%s" appears more than once in the order.', $id->toString()), $e->getMessage());
    }

    public function testUnknownPriceTier(): void
    {
        $id = PriceTierId::generate();
        $e = UnknownPriceTierException::withId($id);

        self::assertInstanceOf(AbstractDomainException::class, self::asThrowable($e));
        self::assertSame('unknown_price_tier', $e->errorCode());
        self::assertSame(\sprintf('Price tier "%s" does not belong to this event.', $id->toString()), $e->getMessage());
    }

    public function testEventNotOnSale(): void
    {
        $id = EventId::generate();
        $e = EventNotOnSaleException::withId($id);

        self::assertInstanceOf(AbstractDomainException::class, self::asThrowable($e));
        self::assertSame('event_not_on_sale', $e->errorCode());
        self::assertSame(\sprintf('Event "%s" is not on sale.', $id->toString()), $e->getMessage());
    }

    private static function asThrowable(\Throwable $e): \Throwable
    {
        return $e;
    }
}
