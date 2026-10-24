<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

final readonly class PriceTierLabel extends AbstractTextValue
{
    protected static function label(): string
    {
        return 'Price tier label';
    }

    protected static function maxLength(): int
    {
        return 60;
    }
}
