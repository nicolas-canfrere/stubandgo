<?php

declare(strict_types=1);

namespace App\Tests\Access\Fixtures;

use App\Access\Core\Domain\TicketTokenGeneratorInterface;
use App\Access\Core\Domain\ValueObject\TicketToken;

/**
 * Decorates the token generator in the test environment: it can be told to fail, and it remembers the tokens.
 */
final class ControllableTicketTokenGenerator implements TicketTokenGeneratorInterface
{
    private bool $failing = false;

    public function __construct(private readonly TicketTokenGeneratorInterface $inner)
    {
    }

    public function failFromNowOn(): void
    {
        $this->failing = true;
    }

    public function generate(): TicketToken
    {
        if ($this->failing) {
            throw new \RuntimeException('Token generation is down.');
        }

        return $this->inner->generate();
    }
}
