<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

final readonly class DeliverTickets implements CommandInterface
{
    public function __construct(public string $orderId)
    {
    }
}
