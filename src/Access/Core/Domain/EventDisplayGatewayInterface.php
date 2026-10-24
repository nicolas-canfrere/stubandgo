<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\EventDisplayInfo;
use App\Shared\Identity\EventId;

interface EventDisplayGatewayInterface
{
    public function forEvent(EventId $eventId): EventDisplayInfo;
}
