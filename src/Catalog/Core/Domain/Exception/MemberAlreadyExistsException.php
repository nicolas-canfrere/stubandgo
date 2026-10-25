<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractConflictException;
use App\Shared\ValueObject\EmailAddress;

final class MemberAlreadyExistsException extends AbstractConflictException
{
    public static function withEmail(EmailAddress $email): self
    {
        return new self(\sprintf('A member with the email address "%s" already exists.', $email->toString()));
    }

    public function errorCode(): string
    {
        return 'member_already_exists';
    }
}
