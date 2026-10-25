<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Exception\AbstractConflictException;

final class OrganizationSlugTakenException extends AbstractConflictException
{
    public static function withSlug(Slug $slug): self
    {
        return new self(\sprintf('Organization slug "%s" is already taken.', $slug->toString()));
    }

    public function errorCode(): string
    {
        return 'organization_slug_taken';
    }
}
