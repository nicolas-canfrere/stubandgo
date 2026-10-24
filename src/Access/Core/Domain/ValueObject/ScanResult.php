<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ScanOutcome;

/**
 * The verdict of a scan: only an already used ticket carries the date of its first use.
 */
final readonly class ScanResult
{
    private function __construct(
        private ScanOutcome $outcome,
        private ?\DateTimeImmutable $firstUsedAt,
    ) {
    }

    public static function valid(): self
    {
        return new self(ScanOutcome::Valid, null);
    }

    public static function alreadyUsed(\DateTimeImmutable $firstUsedAt): self
    {
        return new self(ScanOutcome::AlreadyUsed, $firstUsedAt->setTimezone(new \DateTimeZone('UTC')));
    }

    public static function wrongEvent(): self
    {
        return new self(ScanOutcome::WrongEvent, null);
    }

    public static function unknown(): self
    {
        return new self(ScanOutcome::Unknown, null);
    }

    public function outcome(): ScanOutcome
    {
        return $this->outcome;
    }

    public function firstUsedAt(): ?\DateTimeImmutable
    {
        return $this->firstUsedAt;
    }

    public function equals(self $other): bool
    {
        return $this->outcome === $other->outcome && $this->firstUsedAt == $other->firstUsedAt;
    }
}
