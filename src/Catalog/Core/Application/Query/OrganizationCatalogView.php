<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Query;

final readonly class OrganizationCatalogView
{
    /**
     * @param list<EventSummaryView> $events
     */
    public function __construct(
        public string $organizationId,
        public string $name,
        public string $slug,
        public array $events,
    ) {
    }
}
