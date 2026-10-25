<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure\Keycloak;

use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\MemberAlreadyExistsException;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Catalog\Infrastructure\Keycloak\KeycloakOrganizationDirectory;
use App\Catalog\Infrastructure\Keycloak\ServiceAccountTokenProvider;
use App\Shared\ValueObject\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KeycloakOrganizationDirectoryTest extends TestCase
{
    private const string ORGANIZATION_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01';
    private const string OTHER_ORGANIZATION_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02';
    private const string USER_ID = '611e4664-67c1-4921-bde8-fe725d85ae86';
    private const string ROLE_ID = '541e9b1f-8c21-49e3-bd52-b194aa45df4a';
    private const string ADMIN = 'http://keycloak:8080/admin/realms/stubandgo';

    private ScriptedHttp $http;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testAnExistingOrganizationIsReturnedWithoutCreatingAnything(): void
    {
        $directory = $this->directory(
            ScriptedHttp::json(200, [
                ['id' => self::OTHER_ORGANIZATION_ID, 'name' => 'Rock', 'alias' => 'rock-asso-2'],
                ['id' => self::ORGANIZATION_ID, 'name' => 'Rock', 'alias' => 'rock-asso'],
            ]),
        );

        $id = $directory->ensureOrganization(Slug::fromString('rock-asso'), OrganizationName::fromString('Rock'));

        self::assertSame(self::ORGANIZATION_ID, $id->toString());
        $requests = $this->http->requests();
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame(self::ADMIN . '/organizations', strtok($requests[0]['url'], '?'));
        parse_str((string) parse_url($requests[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['q' => 'alias:rock-asso'], $query);
        self::assertContains('Authorization: Bearer service-token', $requests[0]['headers']);
    }

    public function testAnAbsentOrganizationIsCreatedWithItsNameAndAliasOnly(): void
    {
        $directory = $this->directory(
            ScriptedHttp::json(200, []),
            ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/organizations/' . self::ORGANIZATION_ID]),
        );

        $id = $directory->ensureOrganization(Slug::fromString('rock-asso'), OrganizationName::fromString('Rock Asso'));

        self::assertSame(self::ORGANIZATION_ID, $id->toString());
        $requests = $this->http->requests();
        self::assertCount(2, $requests);
        self::assertSame('POST', $requests[1]['method']);
        self::assertSame(self::ADMIN . '/organizations', $requests[1]['url']);
        self::assertSame(['name' => 'Rock Asso', 'alias' => 'rock-asso'], json_decode($requests[1]['body'], true));
        self::assertContains('Authorization: Bearer service-token', $requests[1]['headers']);
        self::assertTrue($this->http->exhausted());
    }

    public function testALostRaceOnTheAliasAdoptsTheOrganizationCreatedMeanwhile(): void
    {
        $directory = $this->directory(
            ScriptedHttp::json(200, []),
            ScriptedHttp::json(409, ['errorMessage' => 'A organization with the same alias already exists']),
            ScriptedHttp::json(200, [['id' => self::ORGANIZATION_ID, 'name' => 'Rock', 'alias' => 'rock-asso']]),
        );

        $id = $directory->ensureOrganization(Slug::fromString('rock-asso'), OrganizationName::fromString('Rock'));

        self::assertSame(self::ORGANIZATION_ID, $id->toString());
        self::assertCount(3, $this->http->requests());
    }

    public function testANameTakenUnderAnotherAliasIsNotAdopted(): void
    {
        $directory = $this->directory(
            ScriptedHttp::json(200, []),
            ScriptedHttp::json(409, ['errorMessage' => 'A organization with the same name already exists.']),
            ScriptedHttp::json(200, []),
        );

        $this->expectException(IdentityProviderUnavailableException::class);

        $directory->ensureOrganization(Slug::fromString('rock-asso'), OrganizationName::fromString('Rock'));
    }

    /**
     * @param list<MockResponse> $responses
     */
    #[DataProvider('ensureFailures')]
    public function testAnUnusableAnswerWhileEnsuringAnOrganizationIsReportedAsUnavailable(array $responses): void
    {
        $directory = $this->directory(...$responses);

        $this->expectException(IdentityProviderUnavailableException::class);

        $directory->ensureOrganization(Slug::fromString('rock-asso'), OrganizationName::fromString('Rock'));
    }

    /**
     * @return iterable<string, array{list<MockResponse>}>
     */
    public static function ensureFailures(): iterable
    {
        $created = self::ADMIN . '/organizations/' . self::ORGANIZATION_ID;

        yield 'search fails' => [[ScriptedHttp::status(500)]];
        yield 'search unauthorized' => [[ScriptedHttp::json(403, [['id' => self::ORGANIZATION_ID, 'alias' => 'rock-asso']])]];
        yield 'search is not json' => [[new MockResponse('<html>', ['http_code' => 200])]];
        yield 'search transport failure' => [[ScriptedHttp::transportFailure()]];
        yield 'found organization has a malformed identifier' => [[ScriptedHttp::json(200, [['id' => 'nope', 'alias' => 'rock-asso']])]];
        yield 'creation server error' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(500)]];
        yield 'creation forbidden' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(403)]];
        yield 'creation transport failure' => [[ScriptedHttp::json(200, []), ScriptedHttp::transportFailure()]];
        yield 'creation without location' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(201)]];
        yield 'creation with a malformed location' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/organizations/'])]];
        yield 'creation with a location that is no identifier' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/organizations/nope'])]];
        yield 'creation reports a conflict and the retry search fails' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(409), ScriptedHttp::status(500)]];
        yield 'creation accepted with another success status' => [[ScriptedHttp::json(200, []), ScriptedHttp::status(200, ['location: ' . $created])]];
    }

    public function testASearchResultOfAnotherAliasOrWithoutIdentifierIsNotAMatch(): void
    {
        $directory = $this->directory(
            ScriptedHttp::json(200, [['id' => self::OTHER_ORGANIZATION_ID, 'alias' => 'rock'], ['alias' => 'rock-asso'], 'garbage']),
            ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/organizations/' . self::ORGANIZATION_ID]),
        );

        $id = $directory->ensureOrganization(Slug::fromString('rock-asso'), OrganizationName::fromString('Rock'));

        self::assertSame(self::ORGANIZATION_ID, $id->toString());
    }

    public function testAMemberIsCreatedAttachedGrantedItsRoleAndInvited(): void
    {
        $directory = $this->directory(...$this->successfulInvitation());

        $directory->inviteMember(KeycloakOrganizationId::fromString(self::ORGANIZATION_ID), EmailAddress::fromString('Alice@Example.test'), MemberRole::Controller);

        $requests = $this->http->requests();
        self::assertCount(5, $requests);
        self::assertTrue($this->http->exhausted());

        [$create, $member, $roleLookup, $mapping, $email] = $requests;
        self::assertSame(['POST', self::ADMIN . '/users'], [$create['method'], $create['url']]);
        self::assertSame(['username' => 'alice@example.test', 'email' => 'alice@example.test', 'enabled' => true], json_decode($create['body'], true));

        self::assertSame(['POST', self::ADMIN . '/organizations/' . self::ORGANIZATION_ID . '/members'], [$member['method'], $member['url']]);
        self::assertSame('"' . self::USER_ID . '"', $member['body']);

        self::assertSame(['GET', self::ADMIN . '/roles/organization-controller'], [$roleLookup['method'], $roleLookup['url']]);

        self::assertSame(['POST', self::ADMIN . '/users/' . self::USER_ID . '/role-mappings/realm'], [$mapping['method'], $mapping['url']]);
        self::assertSame([['id' => self::ROLE_ID, 'name' => 'organization-controller']], json_decode($mapping['body'], true));

        self::assertSame('PUT', $email['method']);
        self::assertSame(self::ADMIN . '/users/' . self::USER_ID . '/execute-actions-email', strtok($email['url'], '?'));
        parse_str((string) parse_url($email['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['client_id' => 'stubandgo-app', 'redirect_uri' => 'http://localhost:5173/espace', 'lifespan' => '259200'], $query);
        self::assertSame(['UPDATE_PASSWORD'], json_decode($email['body'], true));

        foreach ($requests as $request) {
            self::assertContains('Authorization: Bearer service-token', $request['headers']);
        }
    }

    public function testAnAdminGetsTheAdminRealmRole(): void
    {
        $directory = $this->directory(...$this->successfulInvitation());

        $directory->inviteMember(KeycloakOrganizationId::fromString(self::ORGANIZATION_ID), EmailAddress::fromString('alice@example.test'), MemberRole::Admin);

        self::assertSame(self::ADMIN . '/roles/organization-admin', $this->http->requests()[2]['url']);
        self::assertSame([['id' => self::ROLE_ID, 'name' => 'organization-admin']], json_decode($this->http->requests()[3]['body'], true));
    }

    public function testAnEmailAlreadyKnownIsRejectedWithoutTouchingTheExistingUser(): void
    {
        $directory = $this->directory(ScriptedHttp::json(409, ['errorMessage' => 'User exists with same email']));

        try {
            $directory->inviteMember(KeycloakOrganizationId::fromString(self::ORGANIZATION_ID), EmailAddress::fromString('alice@example.test'), MemberRole::Admin);
            self::fail('The known email was accepted.');
        } catch (MemberAlreadyExistsException) {
        }

        self::assertCount(1, $this->http->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @param list<MockResponse> $responses
     */
    #[DataProvider('userCreationFailures')]
    public function testAFailedUserCreationLeavesNothingToCleanUp(array $responses): void
    {
        $directory = $this->directory(...$responses);

        try {
            $directory->inviteMember(KeycloakOrganizationId::fromString(self::ORGANIZATION_ID), EmailAddress::fromString('alice@example.test'), MemberRole::Admin);
            self::fail('The invitation went through.');
        } catch (IdentityProviderUnavailableException) {
        }

        self::assertCount(1, $this->http->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{list<MockResponse>}>
     */
    public static function userCreationFailures(): iterable
    {
        yield 'server error' => [[ScriptedHttp::status(500)]];
        yield 'forbidden' => [[ScriptedHttp::json(403, ['error' => 'forbidden'])]];
        yield 'transport failure' => [[ScriptedHttp::transportFailure()]];
        yield 'no location' => [[ScriptedHttp::status(201)]];
        yield 'empty location' => [[ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/users/'])]];
        yield 'other success status' => [[ScriptedHttp::status(204, ['location: ' . self::ADMIN . '/users/' . self::USER_ID])]];
    }

    /**
     * @param list<MockResponse> $failingTail the answers from the failing step on
     */
    #[DataProvider('laterStepFailures')]
    public function testAFailureAfterTheUserCreationDeletesTheUser(int $succeeding, array $failingTail): void
    {
        $head = \array_slice($this->successfulInvitation(), 0, $succeeding);
        $directory = $this->directory(...$head, ...$failingTail, ...[ScriptedHttp::status(204)]);

        try {
            $directory->inviteMember(KeycloakOrganizationId::fromString(self::ORGANIZATION_ID), EmailAddress::fromString('alice@example.test'), MemberRole::Admin);
            self::fail('The invitation went through.');
        } catch (IdentityProviderUnavailableException) {
        }

        $requests = $this->http->requests();
        $last = array_pop($requests);
        self::assertNotNull($last);
        self::assertSame(['DELETE', self::ADMIN . '/users/' . self::USER_ID], [$last['method'], $last['url']]);
        self::assertContains('Authorization: Bearer service-token', $last['headers']);
        self::assertTrue($this->http->exhausted());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{int, list<MockResponse>}>
     */
    public static function laterStepFailures(): iterable
    {
        yield 'membership refused' => [1, [ScriptedHttp::status(500)]];
        yield 'membership transport failure' => [1, [ScriptedHttp::transportFailure()]];
        yield 'membership answered with another success status' => [1, [ScriptedHttp::status(204)]];
        yield 'role lookup missing' => [2, [ScriptedHttp::json(404, ['id' => self::ROLE_ID, 'error' => 'Role not found'])]];
        yield 'role lookup without identifier' => [2, [ScriptedHttp::json(200, ['name' => 'organization-admin'])]];
        yield 'role lookup is not json' => [2, [new MockResponse('<html>', ['http_code' => 200])]];
        yield 'role mapping refused' => [3, [ScriptedHttp::json(404, ['error' => 'Role not found'])]];
        yield 'role mapping answered with another success status' => [3, [ScriptedHttp::status(200)]];
        yield 'email refused' => [4, [ScriptedHttp::json(400, ['errorMessage' => 'Invalid redirect uri.'])]];
        yield 'email server error' => [4, [ScriptedHttp::status(500)]];
        yield 'email answered with another success status' => [4, [ScriptedHttp::status(200)]];
    }

    /**
     * @param list<MockResponse> $deletion
     */
    #[DataProvider('failedDeletions')]
    public function testAUserThatCannotBeDeletedIsLoggedAndTheFailureStillReported(array $deletion): void
    {
        $directory = $this->directory(...\array_slice($this->successfulInvitation(), 0, 1), ...[ScriptedHttp::status(500)], ...$deletion);

        try {
            $directory->inviteMember(KeycloakOrganizationId::fromString(self::ORGANIZATION_ID), EmailAddress::fromString('alice@example.test'), MemberRole::Admin);
            self::fail('The invitation went through.');
        } catch (IdentityProviderUnavailableException $exception) {
            self::assertStringNotContainsString('the-secret', $exception->getMessage());
            self::assertStringNotContainsString('service-token', $exception->getMessage());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame(['keycloakUserId' => self::USER_ID], $this->logger->records[0]['context']);
        $serialized = json_encode($this->logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('the-secret', $serialized);
        self::assertStringNotContainsString('service-token', $serialized);
    }

    /**
     * @return iterable<string, array{list<MockResponse>}>
     */
    public static function failedDeletions(): iterable
    {
        yield 'server error' => [[ScriptedHttp::status(500)]];
        yield 'user already gone' => [[ScriptedHttp::json(404, ['error' => 'User not found'])]];
        yield 'transport failure' => [[ScriptedHttp::transportFailure()]];
    }

    private function directory(MockResponse ...$responses): KeycloakOrganizationDirectory
    {
        $tokens = new ScriptedHttp(ScriptedHttp::json(200, ['access_token' => 'service-token', 'expires_in' => 300]));
        $this->http = new ScriptedHttp(...$responses);

        return new KeycloakOrganizationDirectory(
            $this->http->client(),
            new ServiceAccountTokenProvider($tokens->client(), new MockClock('2026-10-25 10:00:00'), 'stubandgo-admin-api', 'the-secret'),
            $this->logger,
        );
    }

    /**
     * @return list<MockResponse>
     */
    private function successfulInvitation(): array
    {
        return [
            ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/users/' . self::USER_ID]),
            ScriptedHttp::status(201, ['location: ' . self::ADMIN . '/organizations/' . self::ORGANIZATION_ID . '/members/' . self::USER_ID]),
            ScriptedHttp::json(200, ['id' => self::ROLE_ID, 'name' => 'organization-admin']),
            ScriptedHttp::status(204),
            ScriptedHttp::status(204),
        ];
    }
}
