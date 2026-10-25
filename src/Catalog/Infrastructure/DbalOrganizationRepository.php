<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationStatus;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;
use Doctrine\DBAL\Connection;

final readonly class DbalOrganizationRepository implements OrganizationRepositoryInterface
{
    private const string COLUMNS = 'id, name, slug, commission_basis_points, keycloak_id, status';

    public function __construct(private Connection $connection)
    {
    }

    public function save(Organization $organization): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO organization (id, name, slug, commission_basis_points, status, keycloak_id)
                VALUES (:id, :name, :slug, :commission, :status, :keycloak)
                ON CONFLICT (id) DO UPDATE SET
                    commission_basis_points = EXCLUDED.commission_basis_points,
                    status = EXCLUDED.status,
                    keycloak_id = EXCLUDED.keycloak_id
                SQL,
            [
                'id' => $organization->id()->toString(),
                'name' => $organization->name()->toString(),
                'slug' => $organization->slug()->toString(),
                'commission' => $organization->commissionRate()->toBasisPoints(),
                'status' => $organization->status()->value,
                'keycloak' => $organization->keycloakId()?->toString(),
            ],
        );
    }

    public function get(OrganizationId $id): Organization
    {
        return $this->find($id) ?? throw OrganizationNotFoundException::withId($id);
    }

    public function find(OrganizationId $id): ?Organization
    {
        /** @var array{id: string, name: string, slug: string, commission_basis_points: int, keycloak_id: string|null, status: string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM organization WHERE id = :id', ['id' => $id->toString()]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function getBySlug(Slug $slug): Organization
    {
        return $this->findBySlug($slug) ?? throw OrganizationNotFoundException::withSlug($slug);
    }

    public function findBySlug(Slug $slug): ?Organization
    {
        /** @var array{id: string, name: string, slug: string, commission_basis_points: int, keycloak_id: string|null, status: string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM organization WHERE slug = :slug', ['slug' => $slug->toString()]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function nameExists(OrganizationName $name): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM organization WHERE name = :name', ['name' => $name->toString()]);
    }

    /**
     * @param array{id: string, name: string, slug: string, commission_basis_points: int, keycloak_id: string|null, status: string} $row
     */
    private function hydrate(array $row): Organization
    {
        return Organization::reconstitute(
            OrganizationId::fromString($row['id']),
            OrganizationName::fromString($row['name']),
            Slug::fromString($row['slug']),
            CommissionRate::fromBasisPoints($row['commission_basis_points']),
            OrganizationStatus::from($row['status']),
            null === $row['keycloak_id'] ? null : KeycloakOrganizationId::fromString($row['keycloak_id']),
        );
    }
}
