<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure;

use App\Catalog\Core\Application\Published\EventSaleTerms;
use App\Catalog\Core\Application\Published\EventSaleTermsProviderInterface;
use App\Catalog\Core\Application\Published\PriceTierTerms;
use App\Sales\Core\Domain\Exception\UnknownPriceTierException;
use App\Sales\Infrastructure\CatalogEventSaleTermsGateway;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class CatalogEventSaleTermsGatewayTest extends TestCase
{
    public function testItTranslatesTheCatalogTerms(): void
    {
        $eventId = EventId::generate();
        $full = PriceTierId::generate();
        $free = PriceTierId::generate();
        $provider = $this->createMock(EventSaleTermsProviderInterface::class);
        $provider->expects(self::once())->method('forEvent')->with($eventId->toString())->willReturn(new EventSaleTerms(
            $eventId->toString(),
            true,
            new \DateTimeImmutable('2026-12-01T21:00:00+01:00'),
            1250,
            [new PriceTierTerms($full->toString(), 2500), new PriceTierTerms($free->toString(), 0)],
        ));

        $terms = new CatalogEventSaleTermsGateway($provider)->forEvent($eventId);

        self::assertNotNull($terms);
        self::assertTrue($eventId->equals($terms->eventId()));
        self::assertTrue($terms->isPublished());
        self::assertSame('2026-12-01T20:00:00+00:00', $terms->startsAt()->format(\DATE_ATOM));
        self::assertSame(1250, $terms->commissionRate()->toBasisPoints());
        self::assertTrue(Money::EUR(2500)->equals($terms->priceOf($full)));
        self::assertTrue(Money::EUR(0)->equals($terms->priceOf($free)));
        $this->expectException(UnknownPriceTierException::class);
        $terms->priceOf(PriceTierId::generate());
    }

    public function testItKeepsAnUnpublishedEventUnpublished(): void
    {
        $eventId = EventId::generate();
        $provider = $this->createStub(EventSaleTermsProviderInterface::class);
        $provider->method('forEvent')->willReturn(new EventSaleTerms($eventId->toString(), false, new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), 0, []));

        $terms = new CatalogEventSaleTermsGateway($provider)->forEvent($eventId);

        self::assertNotNull($terms);
        self::assertFalse($terms->isPublished());
    }

    public function testUnknownEventHasNoTerms(): void
    {
        $provider = $this->createStub(EventSaleTermsProviderInterface::class);
        $provider->method('forEvent')->willReturn(null);

        self::assertNull(new CatalogEventSaleTermsGateway($provider)->forEvent(EventId::generate()));
    }
}
