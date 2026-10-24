<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;
use App\Shared\Identity\PriceTierId;

final class UnknownPriceTierException extends AbstractDomainException
{
    public static function withId(PriceTierId $id): self
    {
        return new self(\sprintf('Price tier "%s" does not belong to this event.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'unknown_price_tier';
    }
}
