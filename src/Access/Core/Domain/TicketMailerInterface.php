<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\EventDisplayInfo;

interface TicketMailerInterface
{
    public function send(TicketBundle $bundle, EventDisplayInfo $event): void;
}
