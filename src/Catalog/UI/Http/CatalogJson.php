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
     * @return array{id: string, title: string, venue: string, startsAt: string, organization: array{slug: string}}
     */
    public static function summary(EventSummaryView $event): array
    {
        return [
            'id' => $event->eventId,
            'title' => $event->title,
            'venue' => $event->venue,
            'startsAt' => self::date($event->startsAt),
            'organization' => ['slug' => $event->organizationSlug],
        ];
    }

    public static function date(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Europe/Paris'))->format(\DATE_ATOM);
    }
}
