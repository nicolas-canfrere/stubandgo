<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\AdmissionRightRepositoryInterface;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Identity\EventId;
use Doctrine\DBAL\Connection;

/**
 * The conditional UPDATE is the atomic consumption: concurrent scans of one token queue on its row lock, and in
 * READ COMMITTED the later ones re-check "used_at IS NULL" against the committed row, so only the first matches.
 * A refused scan then reads the committed row to tell why.
 *
 * @phpstan-type RightRow array{event_id: string, used_at: string|null}
 */
final readonly class DbalAdmissionRightRepository implements AdmissionRightRepositoryInterface
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function consume(TicketToken $token, EventId $eventId, \DateTimeImmutable $now): ScanResult
    {
        $consumed = $this->connection->executeStatement(
            'UPDATE admission_right SET used_at = :now WHERE token = :token AND event_id = :event_id AND used_at IS NULL',
            ['now' => $now->format(self::TIMESTAMP_FORMAT), 'token' => $token->toString(), 'event_id' => $eventId->toString()],
        );

        if (1 === $consumed) {
            return ScanResult::valid();
        }

        /** @var RightRow|false $row */
        $row = $this->connection->fetchAssociative('SELECT event_id, used_at FROM admission_right WHERE token = :token', ['token' => $token->toString()]);

        return match (true) {
            false === $row => ScanResult::unknown(),
            $row['event_id'] === $eventId->toString() && null !== $row['used_at'] => ScanResult::alreadyUsed(new \DateTimeImmutable($row['used_at'])),
            default => ScanResult::wrongEvent(),
        };
    }
}
