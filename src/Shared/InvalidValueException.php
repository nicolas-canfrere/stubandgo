<?php

declare(strict_types=1);

namespace App\Shared;

final class InvalidValueException extends AbstractDomainException
{
    public function errorCode(): string
    {
        return 'invalid_value';
    }
}
