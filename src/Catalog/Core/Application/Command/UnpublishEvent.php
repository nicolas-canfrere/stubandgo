<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

final readonly class UnpublishEvent implements CommandInterface
{
    public function __construct(public string $eventId)
    {
    }
}
