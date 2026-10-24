<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024150000CreateCatalogSchema extends AbstractMigration
{
    private const COMMENTS = [
        'TABLE organization' => 'Organizer selling tickets on the platform; owns events and sets the commission taken on its sales.',
        'COLUMN organization.id' => 'Organization identifier (ULID, canonical uppercase base32).',
        'COLUMN organization.name' => 'Display name of the organization.',
        'COLUMN organization.slug' => 'Unique URL-friendly name of the organization, used to address its public catalog.',
        'COLUMN organization.commission_basis_points' => 'Platform commission on the organization sales, in basis points (100 = 1%), between 0 and 10000.',
        'TABLE event' => 'Event (concert, show...) organized by an organization; a draft until published, then open for sale.',
        'COLUMN event.id' => 'Event identifier (ULID, canonical uppercase base32).',
        'COLUMN event.organization_id' => 'Organization that organizes the event.',
        'COLUMN event.title' => 'Public title of the event.',
        'COLUMN event.description' => 'Public description of the event.',
        'COLUMN event.venue' => 'Name of the place where the event happens.',
        'COLUMN event.starts_at' => 'When the event starts, stored as timestamptz and handled in UTC by the application.',
        'COLUMN event.capacity' => 'Maximum number of seats that can be sold for the event, at least 1.',
        'COLUMN event.status' => 'Publication status: draft (not visible, not on sale) or published.',
        'COLUMN event.locked' => 'True once the first seat has been taken, until every seat is released again; a locked event can no longer be edited.',
        'TABLE price_tier' => 'Price category of an event (for example full price or reduced price).',
        'COLUMN price_tier.id' => 'Price tier identifier (ULID, canonical uppercase base32).',
        'COLUMN price_tier.event_id' => 'Event this price tier belongs to.',
        'COLUMN price_tier.position' => 'Zero-based rank of the tier in the order declared when the event was created; tiers are listed by position.',
        'COLUMN price_tier.label' => 'Public label of the price tier.',
        'COLUMN price_tier.price_cents' => 'Ticket price in euro cents (integer, never negative); the currency is always EUR.',
    ];

    public function getDescription(): string
    {
        return 'Create the organization, event and price_tier tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE organization (
                id CHAR(26) NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(64) NOT NULL,
                commission_basis_points INTEGER NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT organization_slug_unique UNIQUE (slug),
                CONSTRAINT organization_commission_range CHECK (commission_basis_points BETWEEN 0 AND 10000)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event (
                id CHAR(26) NOT NULL,
                organization_id CHAR(26) NOT NULL,
                title VARCHAR(160) NOT NULL,
                description TEXT NOT NULL,
                venue VARCHAR(200) NOT NULL,
                starts_at TIMESTAMPTZ NOT NULL,
                capacity INTEGER NOT NULL,
                status VARCHAR(16) NOT NULL,
                locked BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (id),
                CONSTRAINT event_organization_fk FOREIGN KEY (organization_id) REFERENCES organization (id),
                CONSTRAINT event_capacity_positive CHECK (capacity >= 1),
                CONSTRAINT event_status_known CHECK (status IN ('draft', 'published'))
            )
            SQL);
        $this->addSql('CREATE INDEX event_organization_idx ON event (organization_id)');
        $this->addSql("CREATE INDEX event_published_starts_at_idx ON event (starts_at, id) WHERE status = 'published'");
        $this->addSql(<<<'SQL'
            CREATE TABLE price_tier (
                id CHAR(26) NOT NULL,
                event_id CHAR(26) NOT NULL,
                position INTEGER NOT NULL,
                label VARCHAR(60) NOT NULL,
                price_cents BIGINT NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT price_tier_event_fk FOREIGN KEY (event_id) REFERENCES event (id),
                CONSTRAINT price_tier_price_not_negative CHECK (price_cents >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX price_tier_event_idx ON price_tier (event_id, position)');

        foreach (self::COMMENTS as $target => $comment) {
            $this->addSql(\sprintf('COMMENT ON %s IS %s', $target, $this->connection->quote($comment)));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE price_tier');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE organization');
    }
}
