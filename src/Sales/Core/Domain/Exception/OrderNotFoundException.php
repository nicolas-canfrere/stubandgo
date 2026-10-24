<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\OrderId;

final class OrderNotFoundException extends AbstractNotFoundException
{
    public static function withId(OrderId $id): self
    {
        return new self(\sprintf('Order "%s" not found.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'order_not_found';
    }
}
