<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Command;

use App\Access\Core\Domain\AdmissionRightRepositoryInterface;
use App\Access\Core\Domain\ScanAttempt;
use App\Access\Core\Domain\ScanAttemptRepositoryInterface;
use App\Access\Core\Domain\ValueObject\PresentedTokenFingerprint;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use Psr\Clock\ClockInterface;

/**
 * Every scan is recorded, whatever its outcome: a malformed token is a scan of an unknown ticket, not an error.
 * Consuming the right and recording the attempt share the transaction of the command bus.
 */
final readonly class ScanTicketHandler
{
    public function __construct(
        private AdmissionRightRepositoryInterface $admissionRights,
        private ScanAttemptRepositoryInterface $scanAttempts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidValueException when the event or attempt identifier is not a ULID (nothing is recorded)
     */
    public function __invoke(ScanTicket $command): void
    {
        $id = ScanAttemptId::fromString($command->scanAttemptId);
        $eventId = EventId::fromString($command->eventId);
        $now = $this->clock->now();

        $this->scanAttempts->save(ScanAttempt::record(
            $id,
            $eventId,
            PresentedTokenFingerprint::fromPresented($command->token),
            $this->consume($command->token, $eventId, $now),
            $now,
        ));
    }

    private function consume(#[\SensitiveParameter] string $token, EventId $eventId, \DateTimeImmutable $now): ScanResult
    {
        try {
            $ticketToken = TicketToken::fromString($token);
        } catch (InvalidValueException) {
            return ScanResult::unknown();
        }

        return $this->admissionRights->consume($ticketToken, $eventId, $now);
    }
}
