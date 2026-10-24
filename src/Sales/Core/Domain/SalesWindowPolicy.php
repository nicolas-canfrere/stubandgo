<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\Exception\SalesClosedException;
use App\Sales\Core\Domain\ValueObject\SaleTerms;

final readonly class SalesWindowPolicy
{
    /**
     * @throws EventNotOnSaleException
     * @throws SalesClosedException
     */
    public function assertOpen(SaleTerms $terms, \DateTimeImmutable $now): void
    {
        if (!$terms->isPublished()) {
            throw EventNotOnSaleException::withId($terms->eventId());
        }

        if ($now >= $terms->startsAt()) {
            throw SalesClosedException::forEvent($terms->eventId());
        }
    }
}
