<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Bus\EventBusInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class InsertRowHandler
{
    public function __construct(private Connection $connection, private EventBusInterface $eventBus)
    {
    }

    public function __invoke(InsertRow $command): void
    {
        $this->connection->executeStatement('INSERT INTO fixture_rows (value) VALUES (?)', [$command->value]);
        $this->eventBus->publish(new RowInserted($command->value));

        if ($command->fail) {
            throw new FixtureFailureException('boom');
        }
    }
}
