<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\TicketToken;

interface TicketTokenGeneratorInterface
{
    /**
     * @return TicketToken a new unguessable token: 32 bytes from a cryptographically secure source
     */
    public function generate(): TicketToken;
}
