<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\Exception\ScanAttemptNotFoundException;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;

/**
 * The scan log: an attempt is recorded once and never changes.
 */
interface ScanAttemptRepositoryInterface
{
    public function save(ScanAttempt $attempt): void;

    /**
     * @throws ScanAttemptNotFoundException
     */
    public function get(ScanAttemptId $id): ScanAttempt;
}
