<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Shared\Identity\EventId;

interface EventRepositoryInterface
{
    public function save(Event $event): void;

    /**
     * @throws EventNotFoundException
     */
    public function get(EventId $id): Event;
}
