<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\OrganizationId;

final class OrganizationNotFoundException extends AbstractNotFoundException
{
    public static function withId(OrganizationId $id): self
    {
        return new self(\sprintf('Organization "%s" not found.', $id->toString()));
    }

    public static function withSlug(Slug $slug): self
    {
        return new self(\sprintf('Organization with slug "%s" not found.', $slug->toString()));
    }

    public function errorCode(): string
    {
        return 'organization_not_found';
    }
}
