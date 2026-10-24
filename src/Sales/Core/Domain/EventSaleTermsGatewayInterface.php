<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\ValueObject\SaleTerms;
use App\Shared\Identity\EventId;

interface EventSaleTermsGatewayInterface
{
    public function forEvent(EventId $eventId): ?SaleTerms;
}
