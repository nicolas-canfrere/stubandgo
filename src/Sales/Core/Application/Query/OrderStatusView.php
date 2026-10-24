<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Sales\Core\Domain\Order;
use App\Sales\Core\Domain\ValueObject\PricedOrderLine;

/**
 * What the buyer may see of an order: neither the email address nor the commission split.
 */
final readonly class OrderStatusView
{
    /**
     * @param list<OrderLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $eventId,
        public array $lines,
        public int $totalCents,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->id()->toString(),
            $order->status()->value,
            $order->eventId()->toString(),
            array_map(
                static fn(PricedOrderLine $line): OrderLineView => new OrderLineView(
                    $line->line()->priceTierId->toString(),
                    $line->line()->quantity->toInt(),
                    (int) $line->unitPrice()->getAmount(),
                ),
                $order->lines(),
            ),
            (int) $order->amounts()->total()->getAmount(),
        );
    }
}
