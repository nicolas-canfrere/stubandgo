<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024140000CommentOutboxSchema extends AbstractMigration
{
    private const COMMENTS = [
        'TABLE outbox_message' => 'Transactional outbox: asynchronous messages written in the same transaction as the business change, then published to the message broker by the relay.',
        'COLUMN outbox_message.id' => 'Message identifier (UUID v7, time-ordered); also the tie-breaker when two rows share the same created_at.',
        'COLUMN outbox_message.message_class' => 'Fully qualified class name of the message, used by the relay to rebuild the object from the payload.',
        'COLUMN outbox_message.payload' => 'Normalised message properties (scalars and arrays only), denormalised into message_class by the relay. Never logged.',
        'COLUMN outbox_message.created_at' => 'When the message was stored; the relay publishes pending rows oldest first.',
        'COLUMN outbox_message.published_at' => 'When the relay handed the message to the broker; NULL while the message is still waiting to be published.',
        'COLUMN outbox_message.failed_at' => 'When the relay quarantined the message because it could not be rebuilt; NULL for a healthy message. A quarantined row is never retried.',
        'COLUMN outbox_message.failure_reason' => 'Short explanation (class and error message, no payload) of why the message was quarantined; NULL unless failed_at is set.',
    ];

    public function getDescription(): string
    {
        return 'Document the outbox table and its columns with PostgreSQL comments';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COMMENTS as $target => $comment) {
            $this->addSql(\sprintf('COMMENT ON %s IS %s', $target, $this->connection->quote($comment)));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::COMMENTS) as $target) {
            $this->addSql(\sprintf('COMMENT ON %s IS NULL', $target));
        }
    }
}
