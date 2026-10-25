<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<ScanAttemptView>
 */
final readonly class GetScanAttempt implements QueryInterface
{
    public function __construct(public string $scanAttemptId)
    {
    }
}
