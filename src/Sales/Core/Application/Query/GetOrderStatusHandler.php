<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Sales\Core\Domain\OrderRepositoryInterface;
use App\Shared\Identity\OrderId;

final readonly class GetOrderStatusHandler
{
    public function __construct(private OrderRepositoryInterface $orders)
    {
    }

    public function __invoke(GetOrderStatus $query): OrderStatusView
    {
        return OrderStatusView::fromOrder($this->orders->get(OrderId::fromString($query->orderId)));
    }
}
