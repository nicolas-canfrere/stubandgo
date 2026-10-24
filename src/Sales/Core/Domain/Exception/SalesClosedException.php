<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\Identity\EventId;

final class SalesClosedException extends AbstractConflictException
{
    public static function forEvent(EventId $id): self
    {
        return new self(\sprintf('Sales are closed for event "%s".', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'sales_closed';
    }
}
