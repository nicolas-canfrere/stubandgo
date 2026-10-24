<?php

declare(strict_types=1);

namespace App\Sales\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<EventAvailabilityView>
 */
final readonly class GetEventAvailability implements QueryInterface
{
    public function __construct(public string $eventId)
    {
    }
}
