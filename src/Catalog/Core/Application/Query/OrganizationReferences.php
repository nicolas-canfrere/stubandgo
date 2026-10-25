<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Shared\Identity\OrganizationId;

/**
 * Resolves organization references (slug and name) for one query, reading each organization once.
 */
final class OrganizationReferences
{
    /** @var array<string, OrganizationReferenceView> */
    private array $references = [];

    public function __construct(private readonly OrganizationRepositoryInterface $organizations)
    {
    }

    public function of(OrganizationId $id): OrganizationReferenceView
    {
        return $this->references[$id->toString()] ??= OrganizationReferenceView::fromOrganization($this->organizations->get($id));
    }
}
