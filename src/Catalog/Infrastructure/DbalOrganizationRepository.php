<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;
use Doctrine\DBAL\Connection;

final readonly class DbalOrganizationRepository implements OrganizationRepositoryInterface
{
    private const string COLUMNS = 'id, name, slug, commission_basis_points';

    public function __construct(private Connection $connection)
    {
    }

    public function save(Organization $organization): void
    {
        $this->connection->executeStatement(
            'INSERT INTO organization (id, name, slug, commission_basis_points) VALUES (:id, :name, :slug, :commission)',
            [
                'id' => $organization->id()->toString(),
                'name' => $organization->name()->toString(),
                'slug' => $organization->slug()->toString(),
                'commission' => $organization->commissionRate()->toBasisPoints(),
            ],
        );
    }

    public function get(OrganizationId $id): Organization
    {
        return $this->find($id) ?? throw OrganizationNotFoundException::withId($id);
    }

    public function find(OrganizationId $id): ?Organization
    {
        /** @var array{id: string, name: string, slug: string, commission_basis_points: int}|false $row */
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM organization WHERE id = :id', ['id' => $id->toString()]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function getBySlug(Slug $slug): Organization
    {
        /** @var array{id: string, name: string, slug: string, commission_basis_points: int}|false $row */
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM organization WHERE slug = :slug', ['slug' => $slug->toString()]);

        return false === $row ? throw OrganizationNotFoundException::withSlug($slug) : $this->hydrate($row);
    }

    /**
     * @param array{id: string, name: string, slug: string, commission_basis_points: int} $row
     */
    private function hydrate(array $row): Organization
    {
        return Organization::create(
            OrganizationId::fromString($row['id']),
            OrganizationName::fromString($row['name']),
            Slug::fromString($row['slug']),
            CommissionRate::fromBasisPoints($row['commission_basis_points']),
        );
    }
}
