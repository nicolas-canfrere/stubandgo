<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Command;

use App\Catalog\Core\Application\Command\InviteMember;
use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\MemberAlreadyExistsException;
use App\Catalog\Core\Domain\Exception\OrganizationNotActiveException;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\OrganizationId;
use App\Shared\ValueObject\EmailAddress;
use App\Tests\Catalog\AbstractCatalogTestCase;
use App\Tests\Support\Catalog\InMemoryOrganizationDirectory;
use PHPUnit\Framework\Attributes\DataProvider;

final class InviteMemberHandlerTest extends AbstractCatalogTestCase
{
    private const string KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0c01';

    /**
     * @return iterable<string, array{string, MemberRole}>
     */
    public static function roles(): iterable
    {
        yield 'administrator' => ['admin', MemberRole::Admin];
        yield 'controller' => ['controller', MemberRole::Controller];
    }

    #[DataProvider('roles')]
    public function testTheInvitationIsHandedToTheDirectoryWithTheRoleAndTheKeycloakOrganization(string $role, MemberRole $expected): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);

        $this->command(new InviteMember($organization['id'], 'Nouveau@Rock-Asso.test', $role));

        $invitations = $this->directory()->invitations();
        self::assertCount(1, $invitations);
        self::assertSame(self::KEYCLOAK_ID, $invitations[0]['organization']->toString());
        self::assertSame('nouveau@rock-asso.test', $invitations[0]['email']->toString());
        self::assertSame($expected, $invitations[0]['role']);
    }

    public function testAnOrganizationStillPendingCannotInvite(): void
    {
        $organization = $this->createOrganization();

        try {
            $this->command(new InviteMember($organization['id'], 'nouveau@rock-asso.test', 'admin'));
            self::fail('A pending organization must not invite.');
        } catch (OrganizationNotActiveException) {
            self::assertSame([], $this->directory()->invitations());
        }
    }

    public function testAnUnknownOrganizationIsRejected(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->command(new InviteMember(OrganizationId::generate()->toString(), 'nouveau@rock-asso.test', 'admin'));
    }

    public function testAnAddressAlreadyKnownToTheDirectoryIsRejected(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);
        $this->directory()->knownMember(EmailAddress::fromString('deja@rock-asso.test'));

        $this->expectException(MemberAlreadyExistsException::class);

        $this->command(new InviteMember($organization['id'], 'deja@rock-asso.test', 'controller'));
    }

    public function testADirectoryFailureIsPropagated(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);
        $this->directory()->failNextCall();

        $this->expectException(IdentityProviderUnavailableException::class);

        $this->command(new InviteMember($organization['id'], 'nouveau@rock-asso.test', 'admin'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInvitations(): iterable
    {
        yield 'role owner' => ['nouveau@rock-asso.test', 'owner'];
        yield 'empty role' => ['nouveau@rock-asso.test', ''];
        yield 'role in capitals' => ['nouveau@rock-asso.test', 'ADMIN'];
        yield 'email without domain' => ['nouveau', 'admin'];
        yield 'empty email' => ['', 'admin'];
    }

    #[DataProvider('invalidInvitations')]
    public function testAnInvalidInvitationReachesNoDirectory(string $email, string $role): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: self::KEYCLOAK_ID);

        try {
            $this->command(new InviteMember($organization['id'], $email, $role));
            self::fail('An invalid invitation must be rejected.');
        } catch (InvalidValueException) {
            self::assertSame([], $this->directory()->invitations());
        }
    }

    private function directory(): InMemoryOrganizationDirectory
    {
        return self::getContainer()->get(InMemoryOrganizationDirectory::class);
    }
}
