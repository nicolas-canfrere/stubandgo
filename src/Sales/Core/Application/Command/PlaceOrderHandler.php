<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

use App\Sales\Core\Domain\EventSaleTermsGatewayInterface;
use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\Exception\SoldOutException;
use App\Sales\Core\Domain\Order;
use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Sales\Core\Domain\OrderSizePolicy;
use App\Sales\Core\Domain\OrderStatus;
use App\Sales\Core\Domain\SalesWindowPolicy;
use App\Sales\Core\Domain\SeatStock;
use App\Sales\Core\Domain\SeatStockRepositoryInterface;
use App\Sales\Core\Domain\ValueObject\PricedOrderLine;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Event\EventSeatsFirstTaken;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use Psr\Clock\ClockInterface;

/**
 * Runs inside the command transaction: any exception rolls the seat reservation back.
 */
final readonly class PlaceOrderHandler
{
    public function __construct(
        private EventSaleTermsGatewayInterface $saleTerms,
        private SeatStockRepositoryInterface $seatStocks,
        private OrderRepositoryInterface $orders,
        private EventBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PlaceOrder $command): void
    {
        $orderId = OrderId::fromString($command->orderId);
        $eventId = EventId::fromString($command->eventId);
        $buyerEmail = EmailAddress::fromString($command->buyerEmail);
        $now = $this->clock->now();

        $terms = $this->saleTerms->forEvent($eventId) ?? throw EventNotOnSaleException::withId($eventId);
        new SalesWindowPolicy()->assertOpen($terms, $now);

        $lines = array_map(
            static fn(array $line): OrderLine => new OrderLine(PriceTierId::fromString($line['priceTierId']), Quantity::of($line['quantity'])),
            $command->lines,
        );
        new OrderSizePolicy()->assertAcceptable($lines);
        $pricedLines = array_map(
            static fn(OrderLine $line): PricedOrderLine => new PricedOrderLine($line, $terms->priceOf($line->priceTierId)),
            $lines,
        );

        $order = Order::place($orderId, $eventId, $buyerEmail, $pricedLines, $terms->commissionRate(), $terms->startsAt(), $now);
        $reserved = $order->totalQuantity();
        $taken = $this->seatStocks->reserve($eventId, $reserved) ?? throw SoldOutException::forEvent($eventId);
        $this->orders->save($order);

        if (SeatStock::reservationTookFirstSeats($taken, $reserved)) {
            $this->eventBus->publish(new EventSeatsFirstTaken($eventId));
        }

        if (OrderStatus::Paid === $order->status()) {
            $this->eventBus->publish($order->toOrderPaid());
        }
    }
}
