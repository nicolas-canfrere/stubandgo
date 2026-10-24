<?php

declare(strict_types=1);

namespace App\Shared;

abstract class AbstractDomainException extends \DomainException
{
    abstract public function errorCode(): string;
}
