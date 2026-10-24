<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\Identity\OrderId;

final class OrderNotPendingException extends AbstractConflictException
{
    public static function withId(OrderId $id): self
    {
        return new self(\sprintf('Order "%s" is not pending.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'order_not_pending';
    }
}
