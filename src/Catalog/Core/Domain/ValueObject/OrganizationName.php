<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\ValueObject;

final readonly class OrganizationName extends AbstractTextValue
{
    protected static function label(): string
    {
        return 'Organization name';
    }

    protected static function maxLength(): int
    {
        return 120;
    }
}
