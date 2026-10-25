<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026090000AddOrganizationStatus extends AbstractMigration
{
    private const string COMMENT = 'Provisioning status: pending_keycloak until the organization exists in Keycloak, then active.';

    public function getDescription(): string
    {
        return 'Add the provisioning status to organization and make organization names unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organization ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active'");
        $this->addSql("UPDATE organization SET status = 'pending_keycloak' WHERE keycloak_id IS NULL");
        $this->addSql('ALTER TABLE organization ALTER COLUMN status DROP DEFAULT');
        $this->addSql("ALTER TABLE organization ADD CONSTRAINT organization_status_check CHECK (status IN ('pending_keycloak', 'active'))");
        $this->addSql("ALTER TABLE organization ADD CONSTRAINT organization_status_keycloak_id_check CHECK ((status = 'active') = (keycloak_id IS NOT NULL))");
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT organization_name_key UNIQUE (name)');
        $this->addSql(\sprintf('COMMENT ON COLUMN organization.status IS %s', $this->connection->quote(self::COMMENT)));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT organization_name_key');
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT organization_status_keycloak_id_check');
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT organization_status_check');
        $this->addSql('ALTER TABLE organization DROP COLUMN status');
    }
}
