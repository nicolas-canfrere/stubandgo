<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Query;

use App\Access\Core\Domain\Exception\ScanAttemptNotFoundException;
use App\Access\Core\Domain\ScanAttemptRepositoryInterface;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;

final readonly class GetScanAttemptHandler
{
    public function __construct(private ScanAttemptRepositoryInterface $scanAttempts)
    {
    }

    /**
     * @throws ScanAttemptNotFoundException
     */
    public function __invoke(GetScanAttempt $query): ScanAttemptView
    {
        $result = $this->scanAttempts->get(ScanAttemptId::fromString($query->scanAttemptId))->result();

        return new ScanAttemptView($result->outcome()->value, $result->firstUsedAt());
    }
}
