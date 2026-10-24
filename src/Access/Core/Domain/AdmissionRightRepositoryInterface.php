<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Identity\EventId;

interface AdmissionRightRepositoryInterface
{
    /**
     * Consumes the admission right of the token for the event, atomically: of two concurrent scans of the same
     * token, exactly one is Valid. The result tells why a scan is refused (already used, wrong event, unknown).
     */
    public function consume(TicketToken $token, EventId $eventId, \DateTimeImmutable $now): ScanResult;
}
