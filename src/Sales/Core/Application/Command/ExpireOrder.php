<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

final readonly class ExpireOrder
{
    public function __construct(public string $orderId)
    {
    }
}
