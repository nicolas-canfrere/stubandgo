<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Http;

use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Tests\Catalog\CatalogFixturesTrait;
use App\Tests\Shared\Infrastructure\Observability\LogRecordsTrait;
use App\Tests\Support\Catalog\InMemoryOrganizationDirectory;
use App\Tests\Support\Security\TestTokenIssuer;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformApiTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use LogRecordsTrait;

    private const string KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a55';

    private KernelBrowser $client;
    private InMemoryOrganizationDirectory $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->directory = self::getContainer()->get(InMemoryOrganizationDirectory::class);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'list' => ['GET', '/api/platform/organizations'];
        yield 'create' => ['POST', '/api/platform/organizations'];
        yield 'provision' => ['POST', '/api/platform/organizations/01JAE5Z8Q3M4N6P7R8S9T0V1W2/provisioning'];
        yield 'commission rate' => ['PUT', '/api/platform/organizations/01JAE5Z8Q3M4N6P7R8S9T0V1W2/commission-rate'];
    }

    #[DataProvider('routes')]
    public function testEveryRouteRequiresAnAccessToken(string $method, string $uri): void
    {
        $problem = $this->call($method, $uri, null, []);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame('/problems/unauthorized', $problem['type'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonPlatformAdmins(): iterable
    {
        yield 'controller of an organization' => [[]];
        yield 'administrator of an organization' => [['realm_access' => ['roles' => ['organization-admin']]]];
        yield 'organization member holding the platform-admin role' => [['realm_access' => ['roles' => ['platform-admin']]]];
        yield 'staff member without the platform-admin role' => [['iss' => TestTokenIssuer::PLATFORM_ISSUER, 'realm_access' => ['roles' => ['support']], 'organization' => null]];
        yield 'staff member without any role' => [['iss' => TestTokenIssuer::PLATFORM_ISSUER, 'realm_access' => null, 'organization' => null]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('nonPlatformAdmins')]
    public function testEveryRouteIsForbiddenToWhoIsNotAPlatformAdmin(array $claims): void
    {
        $organization = $this->createOrganization();
        $token = TestTokenIssuer::issue($claims);

        foreach (self::routes() as [$method, $uri]) {
            $problem = $this->call($method, $uri, $token, ['name' => 'Intruders', 'slug' => 'intruders', 'commissionBasisPoints' => 500]);

            self::assertResponseStatusCodeSame(403, $method . ' ' . $uri);
            self::assertSame('/problems/forbidden', $problem['type'] ?? null);
        }
        self::assertSame(1000, $this->listed()[$organization['id']]['commissionBasisPoints'] ?? null);
        self::assertArrayNotHasKey('intruders', array_column($this->listed(), 'id', 'slug'));
    }

    public function testCreationActivatesTheOrganization(): void
    {
        $body = $this->create('Théâtre de la Ville', 'theatre-ville', 1250);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertFalse($this->client->getResponse()->headers->has('Location'), 'No route reads one organization: the creation names no URL.');
        self::assertSame(['id' => $body['id'], 'name' => 'Théâtre de la Ville', 'slug' => 'theatre-ville', 'commissionBasisPoints' => 1250, 'status' => 'active'], $body);
        self::assertCount(1, $this->directory->ensureCalls());
        self::assertNotNull($this->directory->organizationOf(Slug::fromString('theatre-ville')));
    }

    public function testCreationWhileKeycloakIsDownLeavesTheOrganizationPendingUntilItIsRetried(): void
    {
        $this->directory->failNextCall();

        $created = $this->create('Cirque Plume', 'cirque-plume', 800);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending_keycloak', $created['status']);
        $deferred = self::logRecords('organization.provisioning_deferred');
        self::assertCount(1, $deferred);
        self::assertSame(Level::Warning, $deferred[0]->level);
        self::assertSame(['organization_id' => $created['id'], 'error_code' => 'identity_provider_unavailable'], $deferred[0]->context);
        self::assertSame($created, $this->listed()[$this->idOf($created)] ?? null);

        $retried = $this->provision($this->idOf($created));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([...$created, 'status' => 'active'], $retried);
        self::assertSame('active', $this->listed()[$this->idOf($created)]['status'] ?? null);
    }

    public function testCreationAdoptsTheOrganizationThatAlreadyExistsInKeycloak(): void
    {
        $this->directory->preexisting(Slug::fromString('opera-nord'), KeycloakOrganizationId::fromString(self::KEYCLOAK_ID));

        $body = $this->create('Opéra Nord', 'opera-nord', 500);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('active', $body['status']);
    }

    public function testProvisioningAnActiveOrganizationAnswersWithoutCallingKeycloak(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);

        $body = $this->provision($organization['id']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('active', $body['status']);
        self::assertSame($organization['slug'], $body['slug']);
        self::assertSame([], $this->directory->ensureCalls());
    }

    public function testProvisioningWhileKeycloakIsDownAnswersBadGatewayAndKeepsThePendingStatus(): void
    {
        $organization = $this->createOrganization();
        $this->directory->failNextCall();

        $problem = $this->provision($organization['id']);

        self::assertResponseStatusCodeSame(502);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('pending_keycloak', $this->listed()[$organization['id']]['status'] ?? null);
        self::assertSame('/problems/identity_provider_unavailable', $problem['type'] ?? null);
    }

    public function testProvisioningAnUnknownOrganizationIsNotFound(): void
    {
        $problem = $this->provision('01JAE5Z8Q3M4N6P7R8S9T0V1W2');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/organization_not_found', $problem['type'] ?? null);
    }

    public function testASlugAlreadyTakenIsAConflict(): void
    {
        $this->create('Premier', 'meme-slug', 1000);

        $problem = $this->create('Second', 'meme-slug', 1000);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/organization_slug_taken', $problem['type'] ?? null);
    }

    public function testANameAlreadyTakenIsAConflict(): void
    {
        $this->create('Meme Nom', 'premier-slug', 1000);

        $problem = $this->create('Meme Nom', 'second-slug', 1000);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/organization_name_taken', $problem['type'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreations(): iterable
    {
        yield 'slug with an uppercase letter' => [['name' => 'Asso', 'slug' => 'Mal-Forme', 'commissionBasisPoints' => 500], '/problems/invalid_value'];
        yield 'slug with a double hyphen' => [['name' => 'Asso', 'slug' => 'mal--forme', 'commissionBasisPoints' => 500], '/problems/invalid_value'];
        yield 'rate above 100 percent' => [['name' => 'Asso', 'slug' => 'asso-ok', 'commissionBasisPoints' => 10001], '/problems/invalid_value'];
        yield 'negative rate' => [['name' => 'Asso', 'slug' => 'asso-ok', 'commissionBasisPoints' => -1], '/problems/invalid_value'];
        yield 'blank name' => [['name' => '  ', 'slug' => 'asso-ok', 'commissionBasisPoints' => 500], '/problems/invalid_value'];
        yield 'rate that is not an integer' => [['name' => 'Asso', 'slug' => 'asso-ok', 'commissionBasisPoints' => '5%'], '/problems/validation_failed'];
        yield 'missing slug' => [['name' => 'Asso', 'commissionBasisPoints' => 500], '/problems/validation_failed'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidCreations')]
    public function testInvalidCreationIsUnprocessableAndCreatesNothing(array $payload, string $type): void
    {
        $problem = $this->call('POST', '/api/platform/organizations', $this->adminToken(), $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($type, $problem['type'] ?? null);
        self::assertSame([], $this->directory->ensureCalls());
        self::assertArrayNotHasKey('asso-ok', array_column($this->listed(), 'id', 'slug'));
    }

    public function testTheCommissionRateChangeShowsInTheList(): void
    {
        $organization = $this->createOrganization(500);

        $body = $this->changeRate($organization['id'], ['commissionBasisPoints' => 2000]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['id' => $organization['id'], 'name' => $organization['name'], 'slug' => $organization['slug'], 'commissionBasisPoints' => 2000, 'status' => 'pending_keycloak'], $body);
        self::assertSame($body, $this->listed()[$organization['id']] ?? null);
    }

    public function testTheCommissionRateAcceptsBothBounds(): void
    {
        $organization = $this->createOrganization();

        self::assertSame(0, $this->changeRate($organization['id'], ['commissionBasisPoints' => 0])['commissionBasisPoints']);
        self::assertSame(10000, $this->changeRate($organization['id'], ['commissionBasisPoints' => 10000])['commissionBasisPoints']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRates(): iterable
    {
        yield 'above 100 percent' => [['commissionBasisPoints' => 10001], '/problems/invalid_value'];
        yield 'negative' => [['commissionBasisPoints' => -1], '/problems/invalid_value'];
        yield 'not an integer' => [['commissionBasisPoints' => 'high'], '/problems/validation_failed'];
        yield 'missing' => [[], '/problems/validation_failed'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidRates')]
    public function testInvalidRateIsUnprocessableAndKeepsTheCurrentOne(array $payload, string $type): void
    {
        $organization = $this->createOrganization(500);

        $problem = $this->changeRate($organization['id'], $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($type, $problem['type'] ?? null);
        self::assertSame(500, $this->listed()[$organization['id']]['commissionBasisPoints'] ?? null);
    }

    public function testChangingTheRateOfAnUnknownOrganizationIsNotFound(): void
    {
        $problem = $this->changeRate('01JAE5Z8Q3M4N6P7R8S9T0V1W2', ['commissionBasisPoints' => 100]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/problems/organization_not_found', $problem['type'] ?? null);
    }

    public function testTheListIsSortedByName(): void
    {
        $this->create('Zèbre Productions', 'zebre-prod', 100);
        $this->create('Aigle Scène', 'aigle-scene', 100);
        $this->create('Maison du Peuple', 'maison-peuple', 100);

        $this->call('GET', '/api/platform/organizations', $this->adminToken(), null);
        $body = $this->decode();

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertIsArray($body['organizations'] ?? null);
        $names = array_column($body['organizations'], 'name');
        self::assertContains('Aigle Scène', $names);
        self::assertLessThan(array_search('Maison du Peuple', $names, true), array_search('Aigle Scène', $names, true));
        self::assertLessThan(array_search('Zèbre Productions', $names, true), array_search('Maison du Peuple', $names, true));
    }

    public function testApiDocDescribesThePlatformRoutes(): void
    {
        $this->client->request('GET', '/api/doc.json');
        $doc = $this->decode();

        self::assertResponseIsSuccessful();
        $routes = [
            ['/api/platform/organizations', 'get', ['200', '401', '403']],
            ['/api/platform/organizations', 'post', ['201', '401', '403', '409', '422']],
            ['/api/platform/organizations/{organizationId}/provisioning', 'post', ['200', '401', '403', '404', '502']],
            ['/api/platform/organizations/{organizationId}/commission-rate', 'put', ['200', '401', '403', '404', '422']],
        ];
        foreach ($routes as [$path, $method, $statuses]) {
            $operation = $this->docNode($doc, 'paths', $path, $method);
            self::assertIsArray($operation, $method . ' ' . $path);
            self::assertSame([['bearerAuth' => []]], $this->docNode($operation, 'security'));
            self::assertSame(['Platform'], $this->docNode($operation, 'tags'));
            foreach ($statuses as $status) {
                $this->docNode($operation, 'responses', $status);
            }
        }
        $created = $this->docNode($doc, 'paths', '/api/platform/organizations', 'post', 'responses', '201');
        self::assertIsArray($created);
        self::assertArrayNotHasKey('headers', $created);
        $conflict = json_encode($this->docNode($doc, 'paths', '/api/platform/organizations', 'post', 'responses', '409'), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('organization_slug_taken', $conflict);
        self::assertStringContainsString('organization_name_taken', $conflict);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function idOf(array $body): string
    {
        self::assertIsString($body['id'] ?? null);

        return $body['id'];
    }

    /**
     * @param array<array-key, mixed> $doc
     */
    private function docNode(array $doc, string ...$keys): mixed
    {
        $node = $doc;
        foreach ($keys as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function create(string $name, string $slug, int $commissionBasisPoints): array
    {
        return $this->call('POST', '/api/platform/organizations', $this->adminToken(), ['name' => $name, 'slug' => $slug, 'commissionBasisPoints' => $commissionBasisPoints]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function provision(string $organizationId): array
    {
        return $this->call('POST', '/api/platform/organizations/' . $organizationId . '/provisioning', $this->adminToken(), null);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function changeRate(string $organizationId, array $payload): array
    {
        return $this->call('PUT', '/api/platform/organizations/' . $organizationId . '/commission-rate', $this->adminToken(), $payload);
    }

    /**
     * @return array<string, array<array-key, mixed>> the listed organizations by identifier
     */
    private function listed(): array
    {
        $this->call('GET', '/api/platform/organizations', $this->adminToken(), null);
        $body = $this->decode();
        \assert(\is_array($body['organizations'] ?? null));

        $byId = [];
        foreach ($body['organizations'] as $organization) {
            \assert(\is_array($organization) && \is_string($organization['id']));
            $byId[$organization['id']] = $organization;
        }

        return $byId;
    }

    private function adminToken(): string
    {
        return TestTokenIssuer::issue([
            'iss' => TestTokenIssuer::PLATFORM_ISSUER,
            'realm_access' => ['roles' => ['platform-admin']],
            'organization' => null,
            'preferred_username' => 'admin.plateforme',
        ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<array-key, mixed>
     */
    private function call(string $method, string $uri, ?string $accessToken, ?array $payload): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        }
        $this->client->request($method, $uri, server: $server, content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
