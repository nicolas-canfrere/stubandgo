<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;

final class OrderTooLargeException extends AbstractDomainException
{
    public static function exceeding(int $maximum): self
    {
        return new self(\sprintf('An order cannot contain more than %d tickets.', $maximum));
    }

    public function errorCode(): string
    {
        return 'order_too_large';
    }
}
