<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalog;

use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\MemberAlreadyExistsException;
use App\Catalog\Core\Domain\OrganizationDirectoryInterface;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\ValueObject\EmailAddress;
use Symfony\Component\Uid\Uuid;

/**
 * Test double of the identity provider: remembers what it was asked and can be armed to fail or to know an organization already.
 */
final class InMemoryOrganizationDirectory implements OrganizationDirectoryInterface
{
    /** @var array<string, KeycloakOrganizationId> organization identifier by slug */
    private array $organizations = [];
    /** @var list<array{organization: KeycloakOrganizationId, email: EmailAddress, role: MemberRole}> */
    private array $invitations = [];
    /** @var list<string> */
    private array $knownEmails = [];
    /** @var list<array{slug: Slug, name: OrganizationName}> */
    private array $ensureCalls = [];
    private bool $failNextCall = false;
    private ?\Closure $whileEnsuring = null;

    public function ensureOrganization(Slug $slug, OrganizationName $name): KeycloakOrganizationId
    {
        $this->failIfArmed();
        $this->ensureCalls[] = ['slug' => $slug, 'name' => $name];

        if (null !== $this->whileEnsuring) {
            [$sideEffect, $this->whileEnsuring] = [$this->whileEnsuring, null];
            $sideEffect();
        }

        return $this->organizations[$slug->toString()] ??= KeycloakOrganizationId::fromString(Uuid::v4()->toRfc4122());
    }

    public function inviteMember(KeycloakOrganizationId $organization, EmailAddress $email, MemberRole $role): void
    {
        $this->failIfArmed();

        if (\in_array($email->toString(), $this->knownEmails, true)) {
            throw MemberAlreadyExistsException::withEmail($email);
        }

        $this->knownEmails[] = $email->toString();
        $this->invitations[] = ['organization' => $organization, 'email' => $email, 'role' => $role];
    }

    /**
     * The next call, and only that one, fails as if the identity provider were down.
     */
    public function failNextCall(): void
    {
        $this->failNextCall = true;
    }

    /**
     * Runs the side effect, once, while the next organization is ensured: what another request writes while the
     * identity provider is answering.
     */
    public function whileEnsuring(\Closure $sideEffect): void
    {
        $this->whileEnsuring = $sideEffect;
    }

    /**
     * Simulates an organization that already exists in the identity provider under this slug.
     */
    public function preexisting(Slug $slug, KeycloakOrganizationId $id): void
    {
        $this->organizations[$slug->toString()] = $id;
    }

    /**
     * Simulates a member known to the identity provider.
     */
    public function knownMember(EmailAddress $email): void
    {
        $this->knownEmails[] = $email->toString();
    }

    /**
     * @return list<array{slug: Slug, name: OrganizationName}>
     */
    public function ensureCalls(): array
    {
        return $this->ensureCalls;
    }

    /**
     * @return list<array{organization: KeycloakOrganizationId, email: EmailAddress, role: MemberRole}>
     */
    public function invitations(): array
    {
        return $this->invitations;
    }

    public function organizationOf(Slug $slug): ?KeycloakOrganizationId
    {
        return $this->organizations[$slug->toString()] ?? null;
    }

    private function failIfArmed(): void
    {
        if ($this->failNextCall) {
            $this->failNextCall = false;

            throw IdentityProviderUnavailableException::unreachable();
        }
    }
}
