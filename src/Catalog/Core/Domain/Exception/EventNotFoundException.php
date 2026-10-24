<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\EventId;

final class EventNotFoundException extends AbstractNotFoundException
{
    public static function withId(EventId $id): self
    {
        return new self(\sprintf('Event "%s" not found.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'event_not_found';
    }
}
