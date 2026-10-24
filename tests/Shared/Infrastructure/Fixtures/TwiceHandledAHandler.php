<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class TwiceHandledAHandler
{
    public function __invoke(TwiceHandledQuery $query): string
    {
        return 'A';
    }
}
