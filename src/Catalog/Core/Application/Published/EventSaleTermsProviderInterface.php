<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Published;

interface EventSaleTermsProviderInterface
{
    /**
     * Null only when the event does not exist; an unpublished event has terms with published set to false.
     */
    public function forEvent(string $eventId): ?EventSaleTerms;
}
