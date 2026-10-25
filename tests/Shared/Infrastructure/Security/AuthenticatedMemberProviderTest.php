<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\AuthenticatedMemberProvider;
use App\Shared\Infrastructure\Security\MemberRealm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AuthenticatedMemberProviderTest extends TestCase
{
    private const string ORGANIZATIONS_ISSUER = 'https://sso.example/realms/stubandgo';
    private const string PLATFORM_ISSUER = 'https://sso.example/realms/stubandgo-staff';
    private const string ORGANIZATION_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01';

    public function testTheMemberIsBuiltFromTheClaims(): void
    {
        $member = $this->load([
            'iss' => self::ORGANIZATIONS_ISSUER,
            'realm_access' => ['roles' => ['organization-controller', 'organization-admin']],
            'organization' => ['demo-rock-asso' => ['id' => self::ORGANIZATION_ID]],
        ]);

        self::assertSame('user-42', $member->getUserIdentifier());
        self::assertSame(['ROLE_USER'], $member->getRoles());
        self::assertSame(MemberRealm::Organizations, $member->realm());
        self::assertSame(['organization-controller', 'organization-admin'], $member->realmRoles());
        self::assertTrue($member->hasRealmRole('organization-admin'));
        self::assertFalse($member->hasRealmRole('platform-admin'));
        self::assertSame(self::ORGANIZATION_ID, $member->keycloakOrganizationId());
    }

    public function testTheRealmFollowsTheIssuer(): void
    {
        self::assertSame(MemberRealm::Platform, $this->load(['iss' => self::PLATFORM_ISSUER])->realm());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function claimsOfAnUnknownRealm(): iterable
    {
        yield 'issuer absent' => [[]];
        yield 'other issuer' => [['iss' => 'https://sso.example/realms/other']];
        yield 'issuer of the internal host' => [['iss' => 'http://keycloak:8080/realms/stubandgo']];
    }

    /**
     * @param array<mixed> $claims
     */
    #[DataProvider('claimsOfAnUnknownRealm')]
    public function testATokenOfAnUnknownRealmLoadsNoMember(array $claims): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->load($claims);
    }

    public function testATokenWithoutSubjectLoadsNoMember(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->provider()->loadUserByIdentifier('', ['iss' => self::ORGANIZATIONS_ISSUER]);
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function realmAccessClaims(): iterable
    {
        yield 'absent' => [null, []];
        yield 'not an object' => ['organization-controller', []];
        yield 'without roles' => [['other' => ['organization-controller']], []];
        yield 'roles not a list' => [['roles' => 'organization-controller'], []];
        yield 'non-string roles dropped' => [['roles' => [42, 'organization-admin', null, 'organization-controller']], ['organization-admin', 'organization-controller']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('realmAccessClaims')]
    public function testRealmRolesComeFromTheRealmAccessClaim(mixed $realmAccess, array $expected): void
    {
        $member = $this->load(['iss' => self::ORGANIZATIONS_ISSUER, 'realm_access' => $realmAccess]);

        self::assertSame($expected, $member->realmRoles());
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function organizationClaims(): iterable
    {
        yield 'absent' => [null, null];
        yield 'not a map' => ['demo-rock-asso', null];
        yield 'empty' => [[], null];
        yield 'two organizations' => [['a' => ['id' => self::ORGANIZATION_ID], 'b' => ['id' => 'other-id']], null];
        yield 'alias only' => [['demo-rock-asso'], null];
        yield 'without id' => [['demo-rock-asso' => ['name' => 'Rock Asso']], null];
        yield 'id not a string' => [['demo-rock-asso' => ['id' => 42]], null];
        yield 'single organization' => [['demo-rock-asso' => ['id' => self::ORGANIZATION_ID]], self::ORGANIZATION_ID];
    }

    #[DataProvider('organizationClaims')]
    public function testTheOrganizationIsTheSingleOneOfTheOrganizationClaim(mixed $organization, ?string $expected): void
    {
        $member = $this->load(['iss' => self::ORGANIZATIONS_ISSUER, 'organization' => $organization]);

        self::assertSame($expected, $member->keycloakOrganizationId());
    }

    public function testOnlyAuthenticatedMembersAreSupported(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supportsClass(AuthenticatedMember::class));
        self::assertFalse($provider->supportsClass(InMemoryUser::class));
    }

    public function testAMemberIsNeverRefreshed(): void
    {
        $this->expectException(UnsupportedUserException::class);

        $this->provider()->refreshUser($this->load(['iss' => self::ORGANIZATIONS_ISSUER]));
    }

    /**
     * @param array<mixed> $claims
     */
    private function load(array $claims): AuthenticatedMember
    {
        return $this->provider()->loadUserByIdentifier('user-42', $claims);
    }

    private function provider(): AuthenticatedMemberProvider
    {
        return new AuthenticatedMemberProvider(self::ORGANIZATIONS_ISSUER, self::PLATFORM_ISSUER);
    }
}
