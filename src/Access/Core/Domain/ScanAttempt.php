<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\PresentedTokenFingerprint;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Shared\Identity\EventId;

/**
 * One scan at the gate of an event, whatever its outcome (malformed tokens included).
 */
final readonly class ScanAttempt
{
    private function __construct(
        private ScanAttemptId $id,
        private EventId $eventId,
        private PresentedTokenFingerprint $tokenFingerprint,
        private ScanResult $result,
        private \DateTimeImmutable $scannedAt,
    ) {
    }

    public static function record(
        ScanAttemptId $id,
        EventId $eventId,
        PresentedTokenFingerprint $tokenFingerprint,
        ScanResult $result,
        \DateTimeImmutable $scannedAt,
    ): self {
        return new self($id, $eventId, $tokenFingerprint, $result, $scannedAt->setTimezone(new \DateTimeZone('UTC')));
    }

    public function id(): ScanAttemptId
    {
        return $this->id;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function tokenFingerprint(): PresentedTokenFingerprint
    {
        return $this->tokenFingerprint;
    }

    public function result(): ScanResult
    {
        return $this->result;
    }

    public function scannedAt(): \DateTimeImmutable
    {
        return $this->scannedAt;
    }
}
