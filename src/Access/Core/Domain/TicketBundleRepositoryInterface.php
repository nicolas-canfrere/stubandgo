<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\Exception\TicketBundleNotFoundException;
use App\Shared\Identity\OrderId;

interface TicketBundleRepositoryInterface
{
    public function save(TicketBundle $bundle): void;

    /**
     * @throws TicketBundleNotFoundException
     */
    public function get(OrderId $orderId): TicketBundle;

    /**
     * Same as get(), locking the bundle row until the end of the transaction, so that concurrent deliveries of
     * one order are serialised.
     *
     * @throws TicketBundleNotFoundException
     */
    public function getForUpdate(OrderId $orderId): TicketBundle;
}
