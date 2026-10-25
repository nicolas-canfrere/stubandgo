<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<EventSalesView>
 */
final readonly class GetEventSales implements QueryInterface
{
    public function __construct(public string $eventId)
    {
    }
}
