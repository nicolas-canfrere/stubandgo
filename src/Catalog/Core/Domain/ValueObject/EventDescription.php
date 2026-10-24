<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

final readonly class EventDescription extends AbstractTextValue
{
    protected static function label(): string
    {
        return 'Event description';
    }

    protected static function maxLength(): int
    {
        return 5000;
    }
}
