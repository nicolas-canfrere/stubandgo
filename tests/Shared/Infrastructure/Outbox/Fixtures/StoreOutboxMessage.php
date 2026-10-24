<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox\Fixtures;

use App\Shared\Bus\CommandInterface;

final readonly class StoreOutboxMessage implements CommandInterface
{
    public function __construct(public string $text, public bool $fail)
    {
    }
}
