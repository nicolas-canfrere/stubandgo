<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024130000QuarantineOutboxMessages extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add outbox quarantine columns for undeliverable messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox_message ADD failed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE outbox_message ADD failure_reason TEXT DEFAULT NULL');
        $this->addSql('DROP INDEX outbox_message_unpublished_idx');
        $this->addSql('CREATE INDEX outbox_message_unpublished_idx ON outbox_message (created_at, id) WHERE published_at IS NULL AND failed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX outbox_message_unpublished_idx');
        $this->addSql('CREATE INDEX outbox_message_unpublished_idx ON outbox_message (created_at, id) WHERE published_at IS NULL');
        $this->addSql('ALTER TABLE outbox_message DROP failure_reason');
        $this->addSql('ALTER TABLE outbox_message DROP failed_at');
    }
}
