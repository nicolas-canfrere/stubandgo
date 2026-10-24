<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

interface EventDisplayProviderInterface
{
    /**
     * Null only when the event does not exist.
     */
    public function forEvent(string $eventId): ?EventDisplay;
}
