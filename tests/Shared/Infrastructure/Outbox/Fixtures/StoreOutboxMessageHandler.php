<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox\Fixtures;

use App\Shared\Infrastructure\Outbox\OutboxWriterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class StoreOutboxMessageHandler
{
    public function __construct(private OutboxWriterInterface $writer)
    {
    }

    public function __invoke(StoreOutboxMessage $command): void
    {
        $this->writer->store(new OutboxFixtureMessage($command->text, 1));

        if ($command->fail) {
            throw new \RuntimeException('boom');
        }
    }
}
