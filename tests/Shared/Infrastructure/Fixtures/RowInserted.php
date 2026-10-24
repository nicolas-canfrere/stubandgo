<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

final readonly class RowInserted
{
    public function __construct(public string $value)
    {
    }
}
