<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Command;

use App\Catalog\Core\Application\Command\ProvisionOrganization;
use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Identity\OrganizationId;
use App\Tests\Catalog\AbstractCatalogTestCase;
use App\Tests\Support\Catalog\InMemoryOrganizationDirectory;
use Doctrine\DBAL\Connection;

final class ProvisionOrganizationHandlerTest extends AbstractCatalogTestCase
{
    public function testPendingOrganizationBecomesActiveWithTheDirectoryId(): void
    {
        $organization = $this->createOrganization();
        self::assertSame(['pending_keycloak', null], $this->row($organization['id']));

        $this->command(new ProvisionOrganization($organization['id']));

        $directory = $this->directory();
        $keycloakId = $directory->organizationOf(Slug::fromString($organization['slug']));
        self::assertNotNull($keycloakId);
        self::assertSame(['active', $keycloakId->toString()], $this->row($organization['id']));
        self::assertCount(1, $directory->ensureCalls());
        self::assertSame($organization['slug'], $directory->ensureCalls()[0]['slug']->toString());
        self::assertSame('Rock Asso ' . $organization['id'], $directory->ensureCalls()[0]['name']->toString());
    }

    public function testAlreadyActiveOrganizationDoesNotCallTheDirectory(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0b01');

        $this->command(new ProvisionOrganization($organization['id']));

        self::assertSame([], $this->directory()->ensureCalls());
        self::assertSame(['active', '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0b01'], $this->row($organization['id']));
    }

    public function testExistingDirectoryOrganizationIsAdopted(): void
    {
        $organization = $this->createOrganization();
        $this->directory()->preexisting(Slug::fromString($organization['slug']), KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0b02'));

        $this->command(new ProvisionOrganization($organization['id']));

        self::assertSame(['active', '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0b02'], $this->row($organization['id']));
    }

    public function testDirectoryFailureLeavesTheOrganizationPending(): void
    {
        $organization = $this->createOrganization();
        $this->directory()->failNextCall();

        try {
            $this->command(new ProvisionOrganization($organization['id']));
            self::fail('The directory failure must propagate.');
        } catch (IdentityProviderUnavailableException) {
            self::assertSame(['pending_keycloak', null], $this->row($organization['id']));
        }

        $this->command(new ProvisionOrganization($organization['id']));
        self::assertSame('active', $this->row($organization['id'])[0]);
    }

    public function testUnknownOrganizationIsRejected(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->command(new ProvisionOrganization(OrganizationId::generate()->toString()));
    }

    private function directory(): InMemoryOrganizationDirectory
    {
        return self::getContainer()->get(InMemoryOrganizationDirectory::class);
    }

    /**
     * @return array{string, string|null}
     */
    private function row(string $id): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT status, keycloak_id FROM organization WHERE id = ?', [$id]);
        \assert(\is_array($row));

        \assert(\is_string($row['status']) && (null === $row['keycloak_id'] || \is_string($row['keycloak_id'])));

        return [$row['status'], $row['keycloak_id']];
    }
}
