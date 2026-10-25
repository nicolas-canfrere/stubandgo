<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure;

use App\Catalog\Core\Application\Published\EventDisplay;
use App\Catalog\Core\Application\Published\EventDisplayProviderInterface;
use App\Sales\Infrastructure\CatalogEventOfferGateway;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use PHPUnit\Framework\TestCase;

final class CatalogEventOfferGatewayTest extends TestCase
{
    public function testTranslatesTheCapacityAndTheTierLabelsOfTheDisplayData(): void
    {
        $eventId = EventId::generate();
        $tier = PriceTierId::generate()->toString();
        $provider = new class($eventId->toString(), new EventDisplay('Concert', 'La Cigale', new \DateTimeImmutable('2026-12-31T19:30:00+00:00'), [$tier => 'Plein'], 120, true)) implements EventDisplayProviderInterface {
            public function __construct(private readonly string $id, private readonly EventDisplay $display)
            {
            }

            public function forEvent(string $eventId): ?EventDisplay
            {
                return $eventId === $this->id ? $this->display : null;
            }
        };

        $offer = new CatalogEventOfferGateway($provider)->forEvent($eventId);

        self::assertNotNull($offer);
        self::assertSame(120, $offer->capacity);
        self::assertSame([$tier => 'Plein'], $offer->priceTierLabels);
        self::assertTrue($offer->onSale);
    }

    public function testAnUnknownEventHasNoOffer(): void
    {
        $provider = new class implements EventDisplayProviderInterface {
            public function forEvent(string $eventId): ?EventDisplay
            {
                return null;
            }
        };

        self::assertNull(new CatalogEventOfferGateway($provider)->forEvent(EventId::generate()));
    }
}
