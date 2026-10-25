<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\Identity\OrganizationId;

final class OrganizationNotActiveException extends AbstractConflictException
{
    public static function withId(OrganizationId $id): self
    {
        return new self(\sprintf('Organization "%s" is not active yet.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'organization_not_active';
    }
}
