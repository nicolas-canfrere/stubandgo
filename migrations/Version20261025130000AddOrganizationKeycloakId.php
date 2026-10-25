<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025130000AddOrganizationKeycloakId extends AbstractMigration
{
    private const string COMMENT = 'Identifier of the matching Keycloak organization; null until the organization exists in Keycloak.';

    public function getDescription(): string
    {
        return 'Add the Keycloak organization identifier to organization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD COLUMN keycloak_id UUID NULL');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT organization_keycloak_id_key UNIQUE (keycloak_id)');
        $this->addSql(\sprintf('COMMENT ON COLUMN organization.keycloak_id IS %s', $this->connection->quote(self::COMMENT)));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP COLUMN keycloak_id');
    }
}
