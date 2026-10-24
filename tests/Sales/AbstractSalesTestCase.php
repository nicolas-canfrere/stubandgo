<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Sales\Core\Application\Command\PlaceOrder;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\CatalogFixturesTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class AbstractSalesTestCase extends KernelTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    protected const string EVENT_START = '2026-12-01T20:00:00+00:00';

    protected MockClock $clock;

    protected function setUp(): void
    {
        $clock = self::mockTime(new \DateTimeImmutable(self::NOW));
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
    }

    /**
     * A published event on sale, with one price tier per given price.
     *
     * @param list<int> $pricesCents
     *
     * @return array{eventId: string, priceTierIds: list<string>}
     */
    protected function eventOnSale(int $capacity = 100, array $pricesCents = [2500], int $commissionBasisPoints = 1000, string $startsAt = self::EVENT_START): array
    {
        $priceTiers = array_map(
            static fn(int $priceCents): array => ['id' => PriceTierId::generate()->toString(), 'label' => 'Tarif', 'priceCents' => $priceCents],
            $pricesCents,
        );
        $eventId = $this->createEvent($this->createOrganization($commissionBasisPoints)['id'], $startsAt, publish: true, priceTiers: $priceTiers, capacity: $capacity);

        return ['eventId' => $eventId, 'priceTierIds' => array_column($priceTiers, 'id')];
    }

    /**
     * @param list<array{priceTierId: string, quantity: int}> $lines
     */
    protected function placeOrder(string $eventId, array $lines, string $buyerEmail = 'buyer@example.com'): string
    {
        $orderId = OrderId::generate()->toString();
        $this->command(new PlaceOrder($orderId, $eventId, $buyerEmail, $lines));

        return $orderId;
    }

    protected function placeSingleLineOrder(string $eventId, string $priceTierId, int $quantity = 1): string
    {
        return $this->placeOrder($eventId, [['priceTierId' => $priceTierId, 'quantity' => $quantity]]);
    }
}
