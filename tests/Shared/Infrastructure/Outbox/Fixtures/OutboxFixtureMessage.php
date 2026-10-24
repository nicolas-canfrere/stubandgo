<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox\Fixtures;

use App\Shared\Infrastructure\Outbox\AsyncMessageInterface;

final readonly class OutboxFixtureMessage implements AsyncMessageInterface
{
    /**
     * @param list<string> $tags
     */
    public function __construct(public string $text, public int $number, public array $tags = [])
    {
    }
}
