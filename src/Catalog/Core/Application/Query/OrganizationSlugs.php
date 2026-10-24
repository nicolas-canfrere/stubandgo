<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Shared\Identity\OrganizationId;

/**
 * Resolves organization slugs for one query, reading each organization once.
 */
final class OrganizationSlugs
{
    /** @var array<string, string> */
    private array $slugs = [];

    public function __construct(private readonly OrganizationRepositoryInterface $organizations)
    {
    }

    public function of(OrganizationId $id): string
    {
        return $this->slugs[$id->toString()] ??= $this->organizations->get($id)->slug()->toString();
    }
}
