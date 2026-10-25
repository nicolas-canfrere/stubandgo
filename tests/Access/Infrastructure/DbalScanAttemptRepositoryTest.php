<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Core\Domain\Exception\ScanAttemptNotFoundException;
use App\Access\Core\Domain\ScanAttempt;
use App\Access\Core\Domain\ScanAttemptRepositoryInterface;
use App\Access\Core\Domain\ValueObject\PresentedToken;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Shared\Identity\EventId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalScanAttemptRepositoryTest extends KernelTestCase
{
    private ScanAttemptRepositoryInterface $attempts;

    protected function setUp(): void
    {
        $this->attempts = self::getContainer()->get(ScanAttemptRepositoryInterface::class);
    }

    /**
     * @return iterable<string, array{ScanResult}>
     */
    public static function results(): iterable
    {
        yield 'valid' => [ScanResult::valid()];
        yield 'already used' => [ScanResult::alreadyUsed(new \DateTimeImmutable('2026-10-24T13:00:00.654321+01:00'))];
        yield 'wrong event' => [ScanResult::wrongEvent()];
        yield 'unknown' => [ScanResult::unknown()];
    }

    #[DataProvider('results')]
    public function testSavedAttemptIsRebuiltIdentically(ScanResult $result): void
    {
        $attempt = ScanAttempt::record(
            ScanAttemptId::generate(),
            EventId::generate(),
            PresentedToken::fromString("t\0k\u{e9}n"),
            $result,
            new \DateTimeImmutable('2026-10-24T14:00:00.123456+02:00'),
        );

        $this->attempts->save($attempt);

        self::assertEquals($attempt, $this->attempts->get($attempt->id()));
    }

    public function testDatesAreStoredInUtc(): void
    {
        $attempt = ScanAttempt::record(
            ScanAttemptId::generate(),
            EventId::generate(),
            PresentedToken::fromString('abc'),
            ScanResult::alreadyUsed(new \DateTimeImmutable('2026-10-24T13:00:00+01:00')),
            new \DateTimeImmutable('2026-10-24T14:00:00+02:00'),
        );

        $this->attempts->save($attempt);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            "SELECT event_id, token, outcome, to_char(first_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS first_used_at, to_char(scanned_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS scanned_at FROM scan_attempt WHERE id = ?",
            [$attempt->id()->toString()],
        );
        self::assertSame([
            'event_id' => $attempt->eventId()->toString(),
            'token' => 'abc',
            'outcome' => 'already_used',
            'first_used_at' => '2026-10-24 12:00:00',
            'scanned_at' => '2026-10-24 12:00:00',
        ], $row);
    }

    public function testGetUnknownAttemptFails(): void
    {
        $id = ScanAttemptId::generate();

        $this->expectException(ScanAttemptNotFoundException::class);
        $this->expectExceptionMessage(\sprintf('Scan attempt "%s" not found.', $id->toString()));

        $this->attempts->get($id);
    }
}
