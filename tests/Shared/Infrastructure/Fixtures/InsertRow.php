<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Bus\CommandInterface;

final readonly class InsertRow implements CommandInterface
{
    public function __construct(public string $value, public bool $fail = false)
    {
    }
}
