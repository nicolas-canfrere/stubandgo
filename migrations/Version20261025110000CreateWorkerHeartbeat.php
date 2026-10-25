<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025110000CreateWorkerHeartbeat extends AbstractMigration
{
    private const COMMENTS = [
        'TABLE worker_heartbeat' => 'Sign of life of the worker: a single row (id 1) upserted by every outbox relay run; /health declares the worker down when it is older than 60 seconds.',
        'COLUMN worker_heartbeat.id' => 'Always 1: there is a single worker heartbeat.',
        'COLUMN worker_heartbeat.last_seen_at' => 'When the last outbox relay run finished (timestamptz, handled in UTC).',
    ];

    public function getDescription(): string
    {
        return 'Create the worker_heartbeat table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_heartbeat (
                id SMALLINT NOT NULL,
                last_seen_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT worker_heartbeat_single_row CHECK (id = 1)
            )
            SQL);

        foreach (self::COMMENTS as $target => $comment) {
            $this->addSql(\sprintf('COMMENT ON %s IS %s', $target, $this->connection->quote($comment)));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worker_heartbeat');
    }
}
