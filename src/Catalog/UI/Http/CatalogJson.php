<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Core\Application\Query\EventSummaryView;

/**
 * JSON shapes of the public catalog API, shared by the controllers that emit them.
 */
final class CatalogJson
{
    /**
     * @return array{id: string, title: string, venue: string, startsAt: string, organization: array{slug: string, name: string}}
     */
    public static function summary(EventSummaryView $event): array
    {
        return [
            'id' => $event->eventId,
            'title' => $event->title,
            'venue' => $event->venue,
            'startsAt' => self::date($event->startsAt),
            'organization' => ['slug' => $event->organizationSlug, 'name' => $event->organizationName],
        ];
    }

    /**
     * Every date of the API is UTC, RFC 3339 with a Z suffix; the front converts it for display.
     * Dates are already UTC (Event normalises them), so none is converted here: the `p` format
     * prints Z for a zero offset and the real offset otherwise, never mislabelling a date.
     */
    public static function date(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d\TH:i:sp');
    }
}
