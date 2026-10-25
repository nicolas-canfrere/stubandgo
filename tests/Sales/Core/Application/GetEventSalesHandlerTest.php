<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Application;

use App\Sales\Core\Application\Query\GetEventSales;
use App\Sales\Core\Application\Query\GetEventSalesHandler;
use App\Sales\Core\Domain\EventOffer;
use App\Sales\Core\Domain\EventOfferGatewayInterface;
use App\Sales\Core\Domain\EventSalesFigures;
use App\Sales\Core\Domain\EventSalesReadModelInterface;
use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Shared\Identity\EventId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetEventSalesHandlerTest extends TestCase
{
    public function testAnEventUnknownToTheCatalogHasNoSalesReport(): void
    {
        $offers = new class implements EventOfferGatewayInterface {
            public function forEvent(EventId $eventId): ?EventOffer
            {
                return null;
            }
        };
        $sales = new class implements EventSalesReadModelInterface {
            public function forEvent(EventId $eventId): EventSalesFigures
            {
                throw new \LogicException('The sales of an unknown event must not be read.');
            }
        };

        $this->expectException(EventNotOnSaleException::class);

        new GetEventSalesHandler($sales, $offers)(new GetEventSales(EventId::generate()->toString()));
    }

    /**
     * @return iterable<string, array{bool, int|null, int, int}>
     */
    public static function seats(): iterable
    {
        yield 'on sale: the seat stock decides' => [true, 70, 10, 70];
        yield 'on sale, no stock yet: the capacity' => [true, null, 0, 120];
        yield 'off sale: the capacity of the catalog minus the seats taken' => [false, 70, 10, 110];
        yield 'never published: the capacity of the catalog' => [false, null, 0, 120];
    }

    #[DataProvider('seats')]
    public function testSeatsRemaining(bool $onSale, ?int $stockRemaining, int $taken, int $expected): void
    {
        $offers = new class($onSale) implements EventOfferGatewayInterface {
            public function __construct(private readonly bool $onSale)
            {
            }

            public function forEvent(EventId $eventId): EventOffer
            {
                return new EventOffer(120, [], $this->onSale);
            }
        };
        $sales = new class($stockRemaining, $taken) implements EventSalesReadModelInterface {
            public function __construct(private readonly ?int $remaining, private readonly int $taken)
            {
            }

            public function forEvent(EventId $eventId): EventSalesFigures
            {
                return new EventSalesFigures(0, 0, 0, 0, [], $this->remaining, $this->taken);
            }
        };

        $view = new GetEventSalesHandler($sales, $offers)(new GetEventSales(EventId::generate()->toString()));

        self::assertSame($expected, $view->seatsRemaining);
    }
}
