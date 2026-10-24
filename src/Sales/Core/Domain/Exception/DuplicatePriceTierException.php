<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;
use App\Shared\Identity\PriceTierId;

final class DuplicatePriceTierException extends AbstractDomainException
{
    public static function withId(PriceTierId $id): self
    {
        return new self(\sprintf('Price tier "%s" appears more than once in the order.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'duplicate_price_tier';
    }
}
