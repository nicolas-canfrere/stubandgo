<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * An event of any status: the caller has already checked that the event belongs to the organization it administers.
 *
 * @implements QueryInterface<BackofficeEventView>
 */
final readonly class GetBackofficeEvent implements QueryInterface
{
    public function __construct(public string $eventId)
    {
    }
}
