<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ScanOutcome;

final readonly class ScanResult
{
    public ?\DateTimeImmutable $firstUsedAt;

    public function __construct(
        public ScanOutcome $outcome,
        ?\DateTimeImmutable $firstUsedAt,
    ) {
        $this->firstUsedAt = $firstUsedAt?->setTimezone(new \DateTimeZone('UTC'));
    }

    public function equals(self $other): bool
    {
        return $this->outcome === $other->outcome && $this->firstUsedAt == $other->firstUsedAt;
    }
}
