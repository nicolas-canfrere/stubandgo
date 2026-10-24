<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\OrderNotFoundException;
use App\Shared\Identity\OrderId;

interface OrderRepositoryInterface
{
    public function save(Order $order): void;

    /**
     * @throws OrderNotFoundException
     */
    public function get(OrderId $id): Order;
}
