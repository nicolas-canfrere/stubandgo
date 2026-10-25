<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;
use App\Shared\Identity\EventId;

final class EventStartInPastException extends AbstractDomainException
{
    public static function withId(EventId $id): self
    {
        return new self(\sprintf('Event "%s" must start in the future.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'event_start_in_past';
    }
}
