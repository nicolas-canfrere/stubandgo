<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\EventDisplayGatewayInterface;
use App\Access\Core\Domain\ValueObject\EventDisplayInfo;
use App\Catalog\Core\Application\Published\EventDisplayProviderInterface;
use App\Shared\Identity\EventId;

/**
 * Anti-corruption layer: translates the published language of Catalog into the display data of Access.
 */
final readonly class CatalogEventDisplayGateway implements EventDisplayGatewayInterface
{
    public function __construct(private EventDisplayProviderInterface $provider)
    {
    }

    /**
     * @throws \LogicException when the event does not exist: a paid order always references an existing event
     */
    public function forEvent(EventId $eventId): EventDisplayInfo
    {
        $display = $this->provider->forEvent($eventId->toString())
            ?? throw new \LogicException(\sprintf('Event "%s" of a paid order does not exist.', $eventId->toString()));

        return EventDisplayInfo::of($display->title, $display->venue, $display->startsAt, $display->priceTierLabels);
    }
}
