<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\Identity\EventId;

final class SoldOutException extends AbstractConflictException
{
    public static function forEvent(EventId $id): self
    {
        return new self(\sprintf('Event "%s" has not enough seats left.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'sold_out';
    }
}
