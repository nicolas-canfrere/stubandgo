<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus', priority: 0)]
final readonly class SecondFailingListener
{
    public function __invoke(DoubleFailure $event): void
    {
        throw new \LogicException('second');
    }
}
