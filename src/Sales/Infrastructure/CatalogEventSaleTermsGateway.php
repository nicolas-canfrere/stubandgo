<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Catalog\Core\Application\Published\EventSaleTermsProviderInterface;
use App\Sales\Core\Domain\EventSaleTermsGatewayInterface;
use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\SaleTerms;
use App\Shared\Identity\EventId;
use Money\Money;

/**
 * Anti-corruption layer: translates the published language of Catalog into the sale terms of Sales.
 */
final readonly class CatalogEventSaleTermsGateway implements EventSaleTermsGatewayInterface
{
    public function __construct(private EventSaleTermsProviderInterface $provider)
    {
    }

    public function forEvent(EventId $eventId): ?SaleTerms
    {
        $terms = $this->provider->forEvent($eventId->toString());

        if (null === $terms) {
            return null;
        }

        $unitPrices = [];
        foreach ($terms->priceTiers as $tier) {
            $unitPrices[$tier->priceTierId] = Money::EUR($tier->priceCents);
        }

        return SaleTerms::of(
            EventId::fromString($terms->eventId),
            $terms->published,
            $terms->startsAt,
            CommissionRate::fromBasisPoints($terms->commissionBasisPoints),
            $unitPrices,
        );
    }
}
