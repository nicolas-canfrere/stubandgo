<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus', priority: 10)]
final readonly class FirstFailingListener
{
    public function __invoke(DoubleFailure $event): void
    {
        throw new FixtureFailureException('first');
    }
}
