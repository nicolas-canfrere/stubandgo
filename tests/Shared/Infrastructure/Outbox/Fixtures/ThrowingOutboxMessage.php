<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox\Fixtures;

use App\Shared\Infrastructure\Outbox\AsyncMessageInterface;

final readonly class ThrowingOutboxMessage implements AsyncMessageInterface
{
    public function __construct(public string $text)
    {
        throw new \InvalidArgumentException('guard failed');
    }
}
