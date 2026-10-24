<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<OrderStatusView>
 */
final readonly class GetOrderStatus implements QueryInterface
{
    public function __construct(public string $orderId)
    {
    }
}
