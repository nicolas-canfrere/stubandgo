<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Identity\EventId;

/**
 * The right to enter one event, carried by one token. Consuming it is the repository's atomic operation, see
 * AdmissionRightRepositoryInterface.
 */
final readonly class AdmissionRight
{
    private ?\DateTimeImmutable $usedAt;

    public function __construct(
        private TicketToken $token,
        private EventId $eventId,
        ?\DateTimeImmutable $usedAt = null,
    ) {
        $this->usedAt = $usedAt?->setTimezone(new \DateTimeZone('UTC'));
    }

    public function token(): TicketToken
    {
        return $this->token;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }
}
