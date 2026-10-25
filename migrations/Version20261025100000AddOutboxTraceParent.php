<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025100000AddOutboxTraceParent extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the W3C traceparent of the storing request with each outbox message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox_message ADD trace_parent TEXT NULL');
        $this->addSql(\sprintf(
            'COMMENT ON COLUMN outbox_message.trace_parent IS %s',
            $this->connection->quote('W3C traceparent header of the request or message that stored the row; the relay attaches it to the published message so the worker logs carry the same trace id. NULL for rows stored before correlation existed.'),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox_message DROP trace_parent');
    }
}
