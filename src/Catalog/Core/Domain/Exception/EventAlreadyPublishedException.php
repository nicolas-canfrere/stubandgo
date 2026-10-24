<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\Identity\EventId;

final class EventAlreadyPublishedException extends AbstractConflictException
{
    public static function withId(EventId $id): self
    {
        return new self(\sprintf('Event "%s" is already published.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'event_already_published';
    }
}
