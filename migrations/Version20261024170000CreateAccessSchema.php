<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024170000CreateAccessSchema extends AbstractMigration
{
    private const COMMENTS = [
        'TABLE ticket_bundle' => 'Tickets issued for one paid order, owned by the Access context; created in the transaction of the payment and delivered to the buyer once by email.',
        'COLUMN ticket_bundle.order_id' => 'Paid order the bundle was issued for (ULID, canonical uppercase base32); one bundle per order, deliberately no foreign key to the Sales order table, each context owns its tables.',
        'COLUMN ticket_bundle.event_id' => 'Event the tickets give access to (ULID, canonical uppercase base32), no foreign key to the Catalog event table.',
        'COLUMN ticket_bundle.buyer_email' => 'Email address the tickets are delivered to, copied from the order when it was paid; lowercased, personal data.',
        'COLUMN ticket_bundle.delivered_at' => 'When the tickets were emailed to the buyer (timestamptz, handled in UTC); null while the delivery is pending.',
        'TABLE ticket' => 'One ticket of a bundle, bought at one price tier; it carries exactly one admission right.',
        'COLUMN ticket.id' => 'Ticket identifier (ULID, canonical uppercase base32), generated at issuing; ids sort in issuing order.',
        'COLUMN ticket.order_id' => 'Bundle (paid order) the ticket belongs to.',
        'COLUMN ticket.price_tier_id' => 'Price tier the ticket was bought at (identifier from the Catalog context, no foreign key); used to show the tier label in the email.',
        'TABLE admission_right' => 'Right to enter one event, carried by one ticket and presented as a token at the door; consumed once, atomically, by the first valid scan.',
        'COLUMN admission_right.ticket_id' => 'Ticket that carries the right; exactly one right per ticket.',
        'COLUMN admission_right.token' => 'Bearer credential encoded in the QR code: 32 random bytes in base64url without padding (43 characters), unique; never logged.',
        'COLUMN admission_right.event_id' => 'Only event the token is valid for (ULID, canonical uppercase base32), no foreign key to the Catalog event table.',
        'COLUMN admission_right.used_at' => 'When the token was consumed by a scan (timestamptz, handled in UTC); null while the right is unused.',
    ];

    public function getDescription(): string
    {
        return 'Create the ticket_bundle, ticket and admission_right tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_bundle (
                order_id CHAR(26) NOT NULL,
                event_id CHAR(26) NOT NULL,
                buyer_email VARCHAR(254) NOT NULL,
                delivered_at TIMESTAMPTZ NULL,
                PRIMARY KEY (order_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket (
                id CHAR(26) NOT NULL,
                order_id CHAR(26) NOT NULL,
                price_tier_id CHAR(26) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT ticket_bundle_fk FOREIGN KEY (order_id) REFERENCES ticket_bundle (order_id)
            )
            SQL);
        $this->addSql('CREATE INDEX ticket_order_idx ON ticket (order_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE admission_right (
                ticket_id CHAR(26) NOT NULL,
                token CHAR(43) NOT NULL,
                event_id CHAR(26) NOT NULL,
                used_at TIMESTAMPTZ NULL,
                PRIMARY KEY (ticket_id),
                CONSTRAINT admission_right_ticket_fk FOREIGN KEY (ticket_id) REFERENCES ticket (id),
                CONSTRAINT admission_right_token_unique UNIQUE (token)
            )
            SQL);

        foreach (self::COMMENTS as $target => $comment) {
            $this->addSql(\sprintf('COMMENT ON %s IS %s', $target, $this->connection->quote($comment)));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admission_right');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE ticket_bundle');
    }
}
