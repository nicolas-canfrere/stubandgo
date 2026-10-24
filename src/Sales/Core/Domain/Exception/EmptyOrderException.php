<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain\Exception;

use App\Shared\Exception\AbstractDomainException;

final class EmptyOrderException extends AbstractDomainException
{
    public static function create(): self
    {
        return new self('An order must contain at least one line.');
    }

    public function errorCode(): string
    {
        return 'empty_order';
    }
}
