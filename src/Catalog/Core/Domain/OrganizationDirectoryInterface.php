<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\MemberAlreadyExistsException;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\ValueObject\EmailAddress;

/**
 * Port to the identity provider holding the organizations and their members.
 */
interface OrganizationDirectoryInterface
{
    /**
     * Returns the identity provider organization whose alias is the slug, creating it when it does not exist.
     *
     * @throws IdentityProviderUnavailableException
     */
    public function ensureOrganization(Slug $slug, OrganizationName $name): KeycloakOrganizationId;

    /**
     * @throws MemberAlreadyExistsException         when the email address is already known
     * @throws IdentityProviderUnavailableException
     */
    public function inviteMember(KeycloakOrganizationId $organization, EmailAddress $email, MemberRole $role): void;
}
