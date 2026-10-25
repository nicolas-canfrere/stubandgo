<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure\Security;

use App\Access\Infrastructure\Security\ScanEventVoter;
use App\Catalog\Core\Application\Published\EventOrganizationProviderInterface;
use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\MemberRealm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ScanEventVoterTest extends TestCase
{
    private const string EVENT_ID = '01JAE5Z8Q3M4N6P7R8S9T0V1W2';
    private const string UNLINKED_EVENT_ID = '01JAE5Z8Q3M4N6P7R8S9T0V1W3';
    private const string ROCK_ASSO = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01';
    private const string THEATRE = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02';

    public function testAControllerOfTheEventOrganizationMayScan(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->member(), self::EVENT_ID));
    }

    /**
     * @return iterable<string, array{AuthenticatedMember, string}>
     */
    public static function deniedScans(): iterable
    {
        yield 'controller of another organization' => [self::member(organization: self::THEATRE), self::EVENT_ID];
        yield 'member of the organization without the controller role' => [self::member(roles: ['organization-admin']), self::EVENT_ID];
        yield 'controller role in the platform realm' => [self::member(realm: MemberRealm::Platform), self::EVENT_ID];
        yield 'controller without organization' => [self::member(organization: null), self::EVENT_ID];
        yield 'controller without organization, event without Keycloak organization' => [self::member(organization: null), self::UNLINKED_EVENT_ID];
        yield 'event without Keycloak organization' => [self::member(), self::UNLINKED_EVENT_ID];
    }

    #[DataProvider('deniedScans')]
    public function testOtherMembersMayNotScan(AuthenticatedMember $member, string $eventId): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $eventId));
    }

    public function testAnAnonymousUserMayNotScan(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), self::EVENT_ID, [ScanEventVoter::ATTRIBUTE]));
    }

    public function testAUserOtherThanAKeycloakMemberMayNotScan(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('someone', null), 'api');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, self::EVENT_ID, [ScanEventVoter::ATTRIBUTE]));
    }

    public function testItAbstainsOnOtherAttributes(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token($this->member()), self::EVENT_ID, ['ROLE_USER']));
    }

    public function testItAbstainsWhenTheSubjectIsNotAnEventIdentifier(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->token($this->member()), null, [ScanEventVoter::ATTRIBUTE]));
    }

    /**
     * @param list<string> $roles
     */
    private static function member(MemberRealm $realm = MemberRealm::Organizations, array $roles = ['organization-controller'], ?string $organization = self::ROCK_ASSO): AuthenticatedMember
    {
        return new AuthenticatedMember('5d3c2b1a-0f9e-4d8c-b7a6-958473625140', $realm, $roles, $organization);
    }

    private function vote(AuthenticatedMember $member, string $eventId): int
    {
        return $this->voter()->vote($this->token($member), $eventId, [ScanEventVoter::ATTRIBUTE]);
    }

    private function token(AuthenticatedMember $member): TokenInterface
    {
        return new UsernamePasswordToken($member, 'api');
    }

    private function voter(): ScanEventVoter
    {
        return new ScanEventVoter(new class([self::EVENT_ID => self::ROCK_ASSO]) implements EventOrganizationProviderInterface {
            /**
             * @param array<string, string> $organizations Keycloak organization id by event id
             */
            public function __construct(private readonly array $organizations)
            {
            }

            public function keycloakOrganizationIdOf(string $eventId): ?string
            {
                return $this->organizations[$eventId] ?? null;
            }
        });
    }
}
