<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Shared\Identity\EventId;

interface EventSalesReadModelInterface
{
    public function forEvent(EventId $eventId): EventSalesFigures;
}
