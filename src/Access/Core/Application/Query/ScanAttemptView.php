<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Query;

/**
 * What the gate shows after a scan: the outcome (a ScanOutcome value) and, for an already used ticket, the UTC
 * date of its first use. The presented token is never echoed.
 */
final readonly class ScanAttemptView
{
    public function __construct(
        public string $outcome,
        public ?\DateTimeImmutable $firstUsedAt,
    ) {
    }
}
