<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class RowInsertedListener
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(RowInserted $event): void
    {
        $this->connection->executeStatement('INSERT INTO fixture_rows (value) VALUES (?)', ['listener:' . $event->value]);
    }
}
