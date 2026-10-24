<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024120000CreateOutbox extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the transactional outbox table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE outbox_message (
                id UUID NOT NULL,
                message_class TEXT NOT NULL,
                payload JSONB NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                published_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX outbox_message_unpublished_idx ON outbox_message (created_at, id) WHERE published_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox_message');
    }
}
