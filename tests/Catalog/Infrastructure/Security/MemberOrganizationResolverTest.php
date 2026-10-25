<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure\Security;

use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Catalog\Infrastructure\Security\MemberOrganizationResolver;
use App\Shared\Infrastructure\Security\AuthenticatedMember;
use App\Shared\Infrastructure\Security\MemberRealm;
use App\Tests\Support\Catalog\StubMemberOrganizationQueryBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class MemberOrganizationResolverTest extends TestCase
{
    private const string ROCK_ASSO = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01';

    public function testItResolvesTheOrganizationOfTheCurrentMember(): void
    {
        $resolved = $this->resolve($this->member(self::ROCK_ASSO), MemberOrganizationView::class);

        self::assertEquals([new MemberOrganizationView('01JAE5Z8Q3M4N6P7R8S9T0V1W2', 'Rock Asso', 'rock-asso')], $resolved);
    }

    public function testItIgnoresOtherArgumentTypes(): void
    {
        self::assertSame([], $this->resolve($this->member(self::ROCK_ASSO), 'string'));
        self::assertSame([], $this->resolve($this->member(self::ROCK_ASSO), null));
    }

    public function testAMemberWithoutOrganizationIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolve($this->member(null), MemberOrganizationView::class);
    }

    public function testAMemberOfAnOrganizationUnknownToStubandgoIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolve($this->member('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02'), MemberOrganizationView::class);
    }

    public function testAnAnonymousRequestIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolve(null, MemberOrganizationView::class);
    }

    public function testAUserOtherThanAKeycloakMemberIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolve(new InMemoryUser('someone', null), MemberOrganizationView::class);
    }

    private function member(?string $organization): AuthenticatedMember
    {
        return new AuthenticatedMember('5d3c2b1a-0f9e-4d8c-b7a6-958473625140', MemberRealm::Organizations, ['organization-admin'], $organization);
    }

    /**
     * @return list<MemberOrganizationView>
     */
    private function resolve(?UserInterface $user, ?string $type): array
    {
        $storage = new TokenStorage();
        if (null !== $user) {
            $storage->setToken(new UsernamePasswordToken($user, 'api'));
        }
        $resolver = new MemberOrganizationResolver($storage, new StubMemberOrganizationQueryBus(['0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01' => new MemberOrganizationView('01JAE5Z8Q3M4N6P7R8S9T0V1W2', 'Rock Asso', 'rock-asso')]));

        return $resolver->resolve(new Request(), new ArgumentMetadata('organization', $type, false, false, null));
    }
}
