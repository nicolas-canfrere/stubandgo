<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Infrastructure\CatalogEventDisplayGateway;
use App\Catalog\Core\Application\Published\EventDisplay;
use App\Catalog\Core\Application\Published\EventDisplayProviderInterface;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use PHPUnit\Framework\TestCase;

final class CatalogEventDisplayGatewayTest extends TestCase
{
    public function testTranslatesThePublishedDisplayData(): void
    {
        $eventId = EventId::generate();
        $tier = PriceTierId::generate();
        $startsAt = new \DateTimeImmutable('2026-12-31T19:30:00+00:00');
        $provider = new class($eventId->toString(), new EventDisplay('Concert', 'La Cigale', $startsAt, [$tier->toString() => 'Plein'], 100, true)) implements EventDisplayProviderInterface {
            public function __construct(private readonly string $id, private readonly EventDisplay $display)
            {
            }

            public function forEvent(string $eventId): ?EventDisplay
            {
                return $eventId === $this->id ? $this->display : null;
            }
        };

        $info = new CatalogEventDisplayGateway($provider)->forEvent($eventId);

        self::assertSame('Concert', $info->title());
        self::assertSame('La Cigale', $info->venue());
        self::assertEquals($startsAt, $info->startsAt());
        self::assertSame('Plein', $info->labelOf($tier));
    }

    public function testUnknownEventIsALogicError(): void
    {
        $provider = new class implements EventDisplayProviderInterface {
            public function forEvent(string $eventId): ?EventDisplay
            {
                return null;
            }
        };
        $eventId = EventId::generate();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Event "%s" of a paid order does not exist.', $eventId->toString()));

        new CatalogEventDisplayGateway($provider)->forEvent($eventId);
    }
}
