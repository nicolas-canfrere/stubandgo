<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\Organization;

/**
 * The stubandgo organization a member belongs to, as the back-office routes see it.
 */
final readonly class MemberOrganizationView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        return new self($organization->id()->toString(), $organization->name()->toString(), $organization->slug()->toString());
    }
}
