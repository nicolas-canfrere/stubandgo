<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure\Security;

use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Catalog\Infrastructure\Security\OrganizationAdminVoter;
use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\MemberRealm;
use App\Tests\Support\Catalog\StubMemberOrganizationQueryBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class OrganizationAdminVoterTest extends TestCase
{
    public const string ROCK_ASSO = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01';
    private const string UNLINKED = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02';

    public function testAnAdministratorOfALinkedOrganizationIsGranted(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->member()));
    }

    /**
     * @return iterable<string, array{AuthenticatedMember}>
     */
    public static function deniedMembers(): iterable
    {
        yield 'controller' => [self::member(roles: ['organization-controller'])];
        yield 'no role' => [self::member(roles: [])];
        yield 'admin role in the platform realm' => [self::member(realm: MemberRealm::Platform)];
        yield 'administrator without organization' => [self::member(organization: null)];
        yield 'administrator of an organization unknown to stubandgo' => [self::member(organization: self::UNLINKED)];
    }

    #[DataProvider('deniedMembers')]
    public function testOtherMembersAreDenied(AuthenticatedMember $member): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member));
    }

    public function testAnAnonymousUserIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), null, [OrganizationAdminVoter::ATTRIBUTE]));
    }

    public function testAUserOtherThanAKeycloakMemberIsDenied(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('someone', null), 'api');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, null, [OrganizationAdminVoter::ATTRIBUTE]));
    }

    public function testItAbstainsOnOtherAttributes(): void
    {
        $token = new UsernamePasswordToken($this->member(), 'api');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($token, null, ['ROLE_USER']));
    }

    /**
     * @param list<string> $roles
     */
    private static function member(MemberRealm $realm = MemberRealm::Organizations, array $roles = ['organization-admin'], ?string $organization = self::ROCK_ASSO): AuthenticatedMember
    {
        return new AuthenticatedMember('5d3c2b1a-0f9e-4d8c-b7a6-958473625140', $realm, $roles, $organization);
    }

    private function vote(AuthenticatedMember $member): int
    {
        return $this->voter()->vote(new UsernamePasswordToken($member, 'api'), null, [OrganizationAdminVoter::ATTRIBUTE]);
    }

    private function voter(): OrganizationAdminVoter
    {
        return new OrganizationAdminVoter(new StubMemberOrganizationQueryBus([self::ROCK_ASSO => new MemberOrganizationView('01JAE5Z8Q3M4N6P7R8S9T0V1W2', 'Rock Asso', 'rock-asso')]));
    }
}
