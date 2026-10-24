<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

final readonly class PublishEvent
{
    public function __construct(public string $eventId)
    {
    }
}
