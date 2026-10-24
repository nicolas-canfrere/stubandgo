<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\TicketTokenGeneratorInterface;
use App\Access\Core\Domain\ValueObject\TicketToken;

final readonly class RandomTicketTokenGenerator implements TicketTokenGeneratorInterface
{
    public function generate(): TicketToken
    {
        return TicketToken::fromString(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }
}
