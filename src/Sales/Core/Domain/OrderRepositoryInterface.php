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

    /**
     * Loads the order and locks it until the end of the current transaction, so that two transitions of the
     * same order run one after the other.
     *
     * @throws OrderNotFoundException
     */
    public function getForUpdate(OrderId $id): Order;

    /**
     * @return list<OrderId> pending orders whose payment deadline is reached, the oldest deadline first
     */
    public function overduePendingIds(\DateTimeImmutable $now): array;
}
