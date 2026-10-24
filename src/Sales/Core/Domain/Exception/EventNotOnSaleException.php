<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;
use App\Shared\Identity\EventId;

final class EventNotOnSaleException extends AbstractDomainException
{
    public static function withId(EventId $id): self
    {
        return new self(\sprintf('Event "%s" is not on sale.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'event_not_on_sale';
    }
}
