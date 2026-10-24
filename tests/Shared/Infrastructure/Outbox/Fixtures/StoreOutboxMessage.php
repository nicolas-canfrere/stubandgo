<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox\Fixtures;

final readonly class StoreOutboxMessage
{
    public function __construct(public string $text, public bool $fail)
    {
    }
}
