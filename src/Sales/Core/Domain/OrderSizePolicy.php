<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\DuplicatePriceTierException;
use App\Sales\Core\Domain\Exception\EmptyOrderException;
use App\Sales\Core\Domain\Exception\OrderTooLargeException;
use App\Shared\ValueObject\OrderLine;

final readonly class OrderSizePolicy
{
    private const int MAX_TICKETS = 10;

    /**
     * @param list<OrderLine> $lines
     *
     * @throws EmptyOrderException
     * @throws DuplicatePriceTierException
     * @throws OrderTooLargeException
     */
    public function assertAcceptable(array $lines): void
    {
        if ([] === $lines) {
            throw EmptyOrderException::create();
        }

        $seen = [];
        $tickets = 0;

        foreach ($lines as $line) {
            $key = $line->priceTierId->toString();

            if (isset($seen[$key])) {
                throw DuplicatePriceTierException::withId($line->priceTierId);
            }

            $seen[$key] = $line->priceTierId;
            $tickets += $line->quantity->toInt();
        }

        if ($tickets > self::MAX_TICKETS) {
            throw OrderTooLargeException::exceeding(self::MAX_TICKETS);
        }
    }
}
