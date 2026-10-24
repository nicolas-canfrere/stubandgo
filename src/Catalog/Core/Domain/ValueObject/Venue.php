<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

final readonly class Venue extends AbstractTextValue
{
    protected static function label(): string
    {
        return 'Venue';
    }

    protected static function maxLength(): int
    {
        return 200;
    }
}
