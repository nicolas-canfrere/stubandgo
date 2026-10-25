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
}
