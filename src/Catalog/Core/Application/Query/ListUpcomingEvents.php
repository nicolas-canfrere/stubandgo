<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<list<EventSummaryView>>
 */
final readonly class ListUpcomingEvents implements QueryInterface
{
}
