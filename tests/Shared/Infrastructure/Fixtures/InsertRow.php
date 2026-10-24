<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

final readonly class InsertRow
{
    public function __construct(public string $value, public bool $fail = false)
    {
    }
}
