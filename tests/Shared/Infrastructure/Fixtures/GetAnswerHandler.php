<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAnswerHandler
{
    public function __invoke(GetAnswer $query): int
    {
        return 42;
    }
}
