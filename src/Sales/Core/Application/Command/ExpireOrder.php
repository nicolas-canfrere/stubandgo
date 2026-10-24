<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

final readonly class ExpireOrder implements CommandInterface
{
    public function __construct(public string $orderId)
    {
    }
}
