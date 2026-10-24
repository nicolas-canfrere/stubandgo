<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain;

use App\Access\Core\Domain\TicketTokenGeneratorInterface;
use App\Access\Core\Domain\ValueObject\TicketToken;

/**
 * Test double: yields distinct, predictable tokens (the random generator is an infrastructure concern).
 */
final class FixedTicketTokenGenerator implements TicketTokenGeneratorInterface
{
    private int $counter = 0;

    public function generate(): TicketToken
    {
        return TicketToken::fromString(\sprintf('%043d', ++$this->counter));
    }
}
