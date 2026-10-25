<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain;

use App\Access\Core\Domain\ScanAttempt;
use App\Access\Core\Domain\ValueObject\PresentedTokenFingerprint;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Shared\Identity\EventId;
use PHPUnit\Framework\TestCase;

final class ScanAttemptTest extends TestCase
{
    public function testRecordsAnAttempt(): void
    {
        $id = ScanAttemptId::generate();
        $eventId = EventId::generate();
        $token = PresentedTokenFingerprint::fromPresented('abc');
        $result = ScanResult::unknown();

        $attempt = ScanAttempt::record($id, $eventId, $token, $result, new \DateTimeImmutable('2026-12-31T20:30:00+01:00'));

        self::assertSame($id, $attempt->id());
        self::assertSame($eventId, $attempt->eventId());
        self::assertSame($token, $attempt->tokenFingerprint());
        self::assertSame($result, $attempt->result());
        self::assertSame('2026-12-31T19:30:00+00:00', $attempt->scannedAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $attempt->scannedAt()->getTimezone()->getName());
    }
}
