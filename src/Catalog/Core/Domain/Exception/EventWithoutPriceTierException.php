<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;
use App\Shared\Identity\EventId;

final class EventWithoutPriceTierException extends AbstractDomainException
{
    public static function withId(EventId $id): self
    {
        return new self(\sprintf('Event "%s" has no price tier and cannot be published.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'event_without_price_tier';
    }
}
