<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Http;

use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Shared\ValueObject\EmailAddress;
use App\Tests\Catalog\CatalogFixturesTrait;
use App\Tests\Support\Catalog\InMemoryOrganizationDirectory;
use App\Tests\Support\Security\TestTokenIssuer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvitationApiTest extends WebTestCase
{
    use CatalogFixturesTrait;

    private const string BACKOFFICE = '/api/backoffice/invitations';
    private const string OTHER_KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a99';

    private KernelBrowser $client;
    private InMemoryOrganizationDirectory $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->directory = self::getContainer()->get(InMemoryOrganizationDirectory::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'platform' => ['/api/platform/organizations/01JAE5Z8Q3M4N6P7R8S9T0V1W2/invitations'];
        yield 'backoffice' => [self::BACKOFFICE];
    }

    #[DataProvider('routes')]
    public function testEveryRouteRequiresAnAccessToken(string $uri): void
    {
        $problem = $this->invite($uri, null, ['email' => 'nouveau@rock-asso.test', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame('/problems/unauthorized', $problem['type'] ?? null);
    }

    public function testAPlatformAdministratorInvitesAnAdministratorIntoAnyActiveOrganization(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);

        $this->invite($this->platformUri($organization['id']), $this->platformToken(), ['email' => 'Nouveau@Rock-Asso.test', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $invitations = $this->directory->invitations();
        self::assertCount(1, $invitations);
        self::assertSame(TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID, $invitations[0]['organization']->toString());
        self::assertSame('nouveau@rock-asso.test', $invitations[0]['email']->toString());
        self::assertSame(MemberRole::Admin, $invitations[0]['role']);
    }

    public function testInvitingIntoAnUnknownOrganizationIsNotFound(): void
    {
        $problem = $this->invite($this->platformUri('01JAE5Z8Q3M4N6P7R8S9T0V1W2'), $this->platformToken(), ['email' => 'nouveau@rock-asso.test', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/organization_not_found', $problem['type'] ?? null);
        self::assertSame([], $this->directory->invitations());
    }

    public function testInvitingIntoAPendingOrganizationIsAConflict(): void
    {
        $organization = $this->createOrganization();

        $problem = $this->invite($this->platformUri($organization['id']), $this->platformToken(), ['email' => 'nouveau@rock-asso.test', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/organization_not_active', $problem['type'] ?? null);
        self::assertSame([], $this->directory->invitations());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonPlatformAdmins(): iterable
    {
        yield 'administrator of an organization' => [['realm_access' => ['roles' => ['organization-admin']]]];
        yield 'controller of an organization' => [[]];
        yield 'staff member without the platform-admin role' => [['iss' => TestTokenIssuer::PLATFORM_ISSUER, 'realm_access' => ['roles' => ['support']], 'organization' => null]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('nonPlatformAdmins')]
    public function testThePlatformRouteIsForbiddenToWhoIsNotAPlatformAdmin(array $claims): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);

        $problem = $this->invite($this->platformUri($organization['id']), TestTokenIssuer::issue($claims), ['email' => 'nouveau@rock-asso.test', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
        self::assertSame([], $this->directory->invitations());
    }

    /**
     * @return iterable<string, array{string, MemberRole}>
     */
    public static function roles(): iterable
    {
        yield 'administrator' => ['admin', MemberRole::Admin];
        yield 'controller' => ['controller', MemberRole::Controller];
    }

    #[DataProvider('roles')]
    public function testAnAdministratorInvitesIntoTheirOwnOrganization(string $role, MemberRole $expected): void
    {
        $this->createOrganization(keycloakOrganizationId: self::OTHER_KEYCLOAK_ID);
        $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);

        $this->invite(self::BACKOFFICE, $this->adminToken(), ['email' => 'nouveau@rock-asso.test', 'role' => $role]);

        self::assertResponseStatusCodeSame(204);
        $invitations = $this->directory->invitations();
        self::assertCount(1, $invitations);
        self::assertSame(TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID, $invitations[0]['organization']->toString());
        self::assertSame($expected, $invitations[0]['role']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonAdministrators(): iterable
    {
        yield 'controller' => [['realm_access' => ['roles' => ['organization-controller']]]];
        yield 'member without any role' => [['realm_access' => null]];
        yield 'administrator of a Keycloak organization unknown to stubandgo' => [['realm_access' => ['roles' => ['organization-admin']], 'organization' => ['autre' => ['id' => self::OTHER_KEYCLOAK_ID]]]];
        yield 'administrator without organization' => [['realm_access' => ['roles' => ['organization-admin']], 'organization' => null]];
        yield 'administrator of several organizations' => [['realm_access' => ['roles' => ['organization-admin']], 'organization' => ['a' => ['id' => TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID], 'b' => ['id' => self::OTHER_KEYCLOAK_ID]]]];
        yield 'administrator whose organization id is not a UUID' => [['realm_access' => ['roles' => ['organization-admin']], 'organization' => ['a' => ['id' => 'not-a-uuid']]]];
        yield 'platform administrator' => [['iss' => TestTokenIssuer::PLATFORM_ISSUER, 'realm_access' => ['roles' => ['platform-admin']], 'organization' => null]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('nonAdministrators')]
    public function testTheBackofficeRouteIsForbiddenToWhoIsNotAnAdministratorOfAStubandgoOrganization(array $claims): void
    {
        $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);

        $problem = $this->invite(self::BACKOFFICE, TestTokenIssuer::issue($claims), ['email' => 'nouveau@rock-asso.test', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
        self::assertSame([], $this->directory->invitations());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInvitations(): iterable
    {
        yield 'role owner' => [['email' => 'nouveau@rock-asso.test', 'role' => 'owner'], '/problems/invalid_value'];
        yield 'empty role' => [['email' => 'nouveau@rock-asso.test', 'role' => ''], '/problems/invalid_value'];
        yield 'invalid email' => [['email' => 'pas-un-email', 'role' => 'admin'], '/problems/invalid_value'];
        yield 'empty email' => [['email' => '', 'role' => 'admin'], '/problems/invalid_value'];
        yield 'missing role' => [['email' => 'nouveau@rock-asso.test'], '/problems/validation_failed'];
        yield 'missing email' => [['role' => 'admin'], '/problems/validation_failed'];
        yield 'email that is not a string' => [['email' => 42, 'role' => 'admin'], '/problems/validation_failed'];
        yield 'role that is not a string' => [['email' => 'nouveau@rock-asso.test', 'role' => ['admin']], '/problems/validation_failed'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidInvitations')]
    public function testAnInvalidInvitationIsUnprocessableOnBothRoutes(array $payload, string $type): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);

        foreach ([[$this->platformUri($organization['id']), $this->platformToken()], [self::BACKOFFICE, $this->adminToken()]] as [$uri, $token]) {
            $problem = $this->invite($uri, $token, $payload);

            self::assertResponseStatusCodeSame(422, $uri);
            self::assertSame($type, $problem['type'] ?? null, $uri);
        }
        self::assertSame([], $this->directory->invitations());
    }

    public function testAMemberThatAlreadyExistsIsAConflictOnBothRoutes(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);
        $this->directory->knownMember(EmailAddress::fromString('deja@rock-asso.test'));

        foreach ([[$this->platformUri($organization['id']), $this->platformToken()], [self::BACKOFFICE, $this->adminToken()]] as [$uri, $token]) {
            $problem = $this->invite($uri, $token, ['email' => 'deja@rock-asso.test', 'role' => 'controller']);

            self::assertResponseStatusCodeSame(409, $uri);
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
            self::assertSame('/problems/member_already_exists', $problem['type'] ?? null, $uri);
        }
        self::assertSame([], $this->directory->invitations());
    }

    public function testAKeycloakOutageIsBadGatewayOnBothRoutes(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);

        foreach ([[$this->platformUri($organization['id']), $this->platformToken()], [self::BACKOFFICE, $this->adminToken()]] as [$uri, $token]) {
            $this->directory->failNextCall();

            $problem = $this->invite($uri, $token, ['email' => 'nouveau@rock-asso.test', 'role' => 'admin']);

            self::assertResponseStatusCodeSame(502, $uri);
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
            self::assertSame('/problems/identity_provider_unavailable', $problem['type'] ?? null, $uri);
        }
        self::assertSame([], $this->directory->invitations());
    }

    public function testApiDocDescribesTheInvitationRoutes(): void
    {
        $this->client->request('GET', '/api/doc.json');
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertIsArray($doc);
        $routes = [
            ['/api/platform/organizations/{organizationId}/invitations', 'Platform', ['204', '401', '403', '404', '409', '422', '502']],
            ['/api/backoffice/invitations', 'Backoffice', ['204', '401', '403', '409', '422', '502']],
        ];
        foreach ($routes as [$path, $tag, $statuses]) {
            self::assertIsArray($doc['paths'] ?? null);
            self::assertIsArray($doc['paths'][$path] ?? null, $path);
            $operation = $doc['paths'][$path]['post'] ?? null;
            self::assertIsArray($operation, $path);
            self::assertIsArray($operation['responses'] ?? null);
            self::assertSame([['bearerAuth' => []]], $operation['security']);
            self::assertSame([$tag], $operation['tags']);
            foreach ($statuses as $status) {
                self::assertArrayHasKey($status, $operation['responses'], $path);
            }
        }
    }

    private function platformUri(string $organizationId): string
    {
        return '/api/platform/organizations/' . $organizationId . '/invitations';
    }

    private function platformToken(): string
    {
        return TestTokenIssuer::issue([
            'iss' => TestTokenIssuer::PLATFORM_ISSUER,
            'realm_access' => ['roles' => ['platform-admin']],
            'organization' => null,
        ]);
    }

    private function adminToken(): string
    {
        return TestTokenIssuer::issue(['realm_access' => ['roles' => ['organization-admin']]]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function invite(string $uri, ?string $accessToken, array $payload): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        }
        $this->client->request('POST', $uri, server: $server, content: json_encode($payload, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
