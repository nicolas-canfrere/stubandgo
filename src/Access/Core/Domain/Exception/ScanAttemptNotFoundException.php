<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\Exception;

use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Shared\Exception\AbstractNotFoundException;

final class ScanAttemptNotFoundException extends AbstractNotFoundException
{
    public static function forId(ScanAttemptId $id): self
    {
        return new self(\sprintf('Scan attempt "%s" not found.', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'scan_attempt_not_found';
    }
}
