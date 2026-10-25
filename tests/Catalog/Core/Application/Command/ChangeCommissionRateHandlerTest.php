<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Command;

use App\Catalog\Core\Application\Command\ChangeCommissionRate;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\AbstractCatalogTestCase;
use Doctrine\DBAL\Connection;

final class ChangeCommissionRateHandlerTest extends AbstractCatalogTestCase
{
    public function testRateIsChanged(): void
    {
        $organization = $this->createOrganization(1000);

        $this->command(new ChangeCommissionRate($organization['id'], 250));

        self::assertSame(250, $this->connection()->fetchOne('SELECT commission_basis_points FROM organization WHERE id = ?', [$organization['id']]));
    }

    public function testExistingOrderKeepsItsRateAndAmounts(): void
    {
        $organization = $this->createOrganization(1000);
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]);
        $orderId = OrderId::generate()->toString();
        $this->command(new PlaceOrder($orderId, $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 2]]));

        $this->command(new ChangeCommissionRate($organization['id'], 0));

        self::assertSame([5000, 500, 4500], $this->amounts($orderId));
        $later = OrderId::generate()->toString();
        $this->command(new PlaceOrder($later, $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 1]]));
        self::assertSame([2500, 0, 2500], $this->amounts($later));
    }

    public function testInvalidRateIsRejected(): void
    {
        $organization = $this->createOrganization(1000);

        $this->expectException(InvalidValueException::class);

        $this->command(new ChangeCommissionRate($organization['id'], 10001));
    }

    public function testUnknownOrganizationIsRejected(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->command(new ChangeCommissionRate(OrganizationId::generate()->toString(), 100));
    }

    /**
     * @return list<int>
     */
    private function amounts(string $orderId): array
    {
        $row = $this->connection()->fetchNumeric('SELECT total_cents, commission_cents, net_cents FROM sales_order WHERE id = ?', [$orderId]);
        \assert(\is_array($row));

        return array_map(static fn(mixed $cents): int => \is_int($cents) ? $cents : throw new \UnexpectedValueException('Amount is not an integer.'), $row);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
