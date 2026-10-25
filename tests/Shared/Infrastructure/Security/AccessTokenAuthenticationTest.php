<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Tests\Shared\Infrastructure\Observability\LogRecordsTrait;
use App\Tests\Support\Security\TestTokenIssuer;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessTokenAuthenticationTest extends WebTestCase
{
    use LogRecordsTrait;

    private const string WHOAMI = '/api/test/whoami';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAProtectedRouteWithoutTokenAnswersUnauthorized(): void
    {
        $this->client->request('GET', self::WHOAMI);

        $this->assertUnauthorized('Bearer');
    }

    /**
     * @return iterable<string, array{\Closure(): string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'expired' => [static fn(): string => TestTokenIssuer::issue(['exp' => time() - 10, 'iat' => time() - 310])];
        yield 'signed by an unknown key' => [static fn(): string => TestTokenIssuer::issueWithForeignKey()];
        yield 'signed by the encryption key of the key set' => [static fn(): string => TestTokenIssuer::issueWithEncryptionKey()];
        yield 'audience of another client' => [static fn(): string => TestTokenIssuer::issue(['aud' => 'account'])];
        yield 'audience missing' => [static fn(): string => TestTokenIssuer::issue(['aud' => null])];
        yield 'issuer announced on the internal host' => [static fn(): string => TestTokenIssuer::issue(['iss' => 'http://keycloak:8080/realms/stubandgo'])];
        yield 'unknown issuer' => [static fn(): string => TestTokenIssuer::issue(['iss' => 'http://localhost:8180/realms/other'])];
        yield 'not a JWT' => [static fn(): string => 'not-a-jwt'];
    }

    /**
     * @param \Closure(): string $token
     */
    #[DataProvider('invalidTokens')]
    public function testAnInvalidTokenIsRejected(\Closure $token): void
    {
        $this->requestWithToken(self::WHOAMI, $token());

        $this->assertUnauthorized('Bearer error="invalid_token"');
    }

    public function testAControllerOfRockAssoIsAuthenticatedWithTheirOrganization(): void
    {
        self::assertSame([
            'realm' => 'organizations',
            'roles' => ['organization-controller'],
            'keycloakOrganizationId' => TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID,
        ], $this->whoAmI(TestTokenIssuer::issue()));
    }

    public function testAnAudienceListContainingTheApiIsAccepted(): void
    {
        $member = $this->whoAmI(TestTokenIssuer::issue(['aud' => ['account', 'stubandgo-api']]));

        self::assertSame('organizations', $member['realm']);
    }

    public function testAPlatformAdministratorComesFromThePlatformRealm(): void
    {
        self::assertSame([
            'realm' => 'platform',
            'roles' => ['platform-admin'],
            'keycloakOrganizationId' => null,
        ], $this->whoAmI(TestTokenIssuer::issue([
            'iss' => TestTokenIssuer::PLATFORM_ISSUER,
            'realm_access' => ['roles' => ['platform-admin']],
            'organization' => null,
        ])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function organizationClaimsWithoutASingleOrganization(): iterable
    {
        yield 'claim absent' => [null];
        yield 'two organizations' => [[
            'demo-rock-asso' => ['id' => TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID],
            'demo-theatre-ville' => ['id' => '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02'],
        ]];
    }

    #[DataProvider('organizationClaimsWithoutASingleOrganization')]
    public function testAMemberWithoutASingleOrganizationHasNoOrganization(mixed $organization): void
    {
        $member = $this->whoAmI(TestTokenIssuer::issue(['organization' => $organization]));

        self::assertNull($member['keycloakOrganizationId']);
    }

    public function testAPublicRouteStaysAnonymousWithoutToken(): void
    {
        $this->client->request('GET', '/api/events');

        self::assertResponseIsSuccessful();
    }

    public function testAPublicRouteRejectsAPresentedInvalidToken(): void
    {
        $this->requestWithToken('/api/events', TestTokenIssuer::issueWithForeignKey());

        $this->assertUnauthorized('Bearer error="invalid_token"');
    }

    public function testTheReasonAnInvalidTokenIsRejectedIsLoggedAsInformationWithoutTrace(): void
    {
        $this->requestWithToken(self::WHOAMI, TestTokenIssuer::issue(['aud' => 'account']));

        $records = self::logRecords('An error occurred while decoding and validating the token.');
        self::assertCount(1, $records);
        self::assertSame(Level::Info, $records[0]->level);
        self::assertSame('Bad audience.', $records[0]->context['error']);
        self::assertArrayNotHasKey('trace', $records[0]->context);
    }

    public function testAPublicRouteAcceptsAValidToken(): void
    {
        $this->requestWithToken('/api/events', TestTokenIssuer::issue());

        self::assertResponseIsSuccessful();
    }

    private function requestWithToken(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /**
     * @return array<mixed>
     */
    private function whoAmI(string $token): array
    {
        $this->requestWithToken(self::WHOAMI, $token);

        self::assertResponseIsSuccessful();
        $member = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($member);

        return $member;
    }

    private function assertUnauthorized(string $challenge): void
    {
        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame($challenge, $response->headers->get('WWW-Authenticate'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('/problems/unauthorized', $problem['type']);
        self::assertSame('Unauthorized', $problem['title']);
        self::assertSame(401, $problem['status']);
        self::assertLoggedAsRejection();
    }
}
