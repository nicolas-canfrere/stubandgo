<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Shared\Exception\AbstractConflictException;

final class OrganizationNameTakenException extends AbstractConflictException
{
    public static function withName(OrganizationName $name): self
    {
        return new self(\sprintf('Organization name "%s" is already taken.', $name->toString()));
    }

    public function errorCode(): string
    {
        return 'organization_name_taken';
    }
}
