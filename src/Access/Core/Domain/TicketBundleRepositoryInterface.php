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
}
