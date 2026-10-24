<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FailingQueryHandler
{
    public function __invoke(FailingQuery $query): never
    {
        throw new FixtureFailureException('query failed');
    }
}
