<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

final readonly class EventTitle extends AbstractTextValue
{
    protected static function label(): string
    {
        return 'Event title';
    }

    protected static function maxLength(): int
    {
        return 160;
    }
}
