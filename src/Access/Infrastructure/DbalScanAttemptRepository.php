<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\Exception\ScanAttemptNotFoundException;
use App\Access\Core\Domain\ScanAttempt;
use App\Access\Core\Domain\ScanAttemptRepositoryInterface;
use App\Access\Core\Domain\ScanOutcome;
use App\Access\Core\Domain\ValueObject\PresentedToken;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Shared\Identity\EventId;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-type AttemptRow array{event_id: string, token: string, outcome: string, first_used_at: string|null, scanned_at: string}
 */
final readonly class DbalScanAttemptRepository implements ScanAttemptRepositoryInterface
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function save(ScanAttempt $attempt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO scan_attempt (id, event_id, token, outcome, first_used_at, scanned_at)
             VALUES (:id, :event_id, :token, :outcome, :first_used_at, :scanned_at)',
            [
                'id' => $attempt->id()->toString(),
                'event_id' => $attempt->eventId()->toString(),
                'token' => $attempt->presentedToken()->toString(),
                'outcome' => $attempt->result()->outcome()->value,
                'first_used_at' => $attempt->result()->firstUsedAt()?->format(self::TIMESTAMP_FORMAT),
                'scanned_at' => $attempt->scannedAt()->format(self::TIMESTAMP_FORMAT),
            ],
        );
    }

    public function get(ScanAttemptId $id): ScanAttempt
    {
        /** @var AttemptRow|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT event_id, token, outcome, first_used_at, scanned_at FROM scan_attempt WHERE id = :id',
            ['id' => $id->toString()],
        );

        if (false === $row) {
            throw ScanAttemptNotFoundException::forId($id);
        }

        return ScanAttempt::record(
            $id,
            EventId::fromString($row['event_id']),
            PresentedToken::fromString($row['token']),
            ScanResult::reconstitute(
                ScanOutcome::from($row['outcome']),
                null === $row['first_used_at'] ? null : new \DateTimeImmutable($row['first_used_at']),
            ),
            new \DateTimeImmutable($row['scanned_at']),
        );
    }
}
