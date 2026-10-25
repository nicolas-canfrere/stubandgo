<?php

declare(strict_types=1);

namespace App\Access\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

/**
 * A token presented at the gate of an event; the caller generates the ULID of the recorded attempt.
 */
final readonly class ScanTicket implements CommandInterface
{
    public function __construct(
        public string $scanAttemptId,
        public string $eventId,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
