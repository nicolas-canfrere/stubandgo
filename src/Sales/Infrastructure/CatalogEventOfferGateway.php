<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Catalog\Core\Application\Published\EventDisplayProviderInterface;
use App\Sales\Core\Domain\EventOffer;
use App\Sales\Core\Domain\EventOfferGatewayInterface;
use App\Shared\Identity\EventId;

/**
 * Anti-corruption layer: translates the published language of Catalog into the offer of an event.
 */
final readonly class CatalogEventOfferGateway implements EventOfferGatewayInterface
{
    public function __construct(private EventDisplayProviderInterface $provider)
    {
    }

    public function forEvent(EventId $eventId): ?EventOffer
    {
        $display = $this->provider->forEvent($eventId->toString());

        return null === $display ? null : new EventOffer($display->capacity, $display->priceTierLabels, $display->published);
    }
}
