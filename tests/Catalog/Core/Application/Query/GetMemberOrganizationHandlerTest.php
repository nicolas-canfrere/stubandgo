<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Query;

use App\Catalog\Core\Application\Query\GetMemberOrganization;
use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Tests\Catalog\AbstractCatalogTestCase;

final class GetMemberOrganizationHandlerTest extends AbstractCatalogTestCase
{
    private const string KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0c02';

    public function testTheOrganizationLinkedToTheKeycloakOrganizationIsReturned(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);

        $view = $this->ask(new GetMemberOrganization(strtoupper(self::KEYCLOAK_ID)));

        self::assertEquals(new MemberOrganizationView($organization['id'], $organization['name'], $organization['slug']), $view);
    }

    public function testAKeycloakOrganizationLinkedToNoOrganizationIsNull(): void
    {
        $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);

        self::assertNull($this->ask(new GetMemberOrganization('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0c99')));
    }

    public function testAPendingOrganizationHasNoKeycloakOrganizationToBeFoundBy(): void
    {
        $this->createOrganization();

        self::assertNull($this->ask(new GetMemberOrganization(self::KEYCLOAK_ID)));
    }

    public function testAnIdentifierThatIsNotAUuidIsNull(): void
    {
        self::assertNull($this->ask(new GetMemberOrganization('not-a-uuid')));
    }
}
