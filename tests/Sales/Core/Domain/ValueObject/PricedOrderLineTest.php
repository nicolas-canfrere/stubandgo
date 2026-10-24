<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain\ValueObject;

use App\Sales\Core\Domain\ValueObject\PricedOrderLine;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class PricedOrderLineTest extends TestCase
{
    public function testExposesItsStateAndSubtotal(): void
    {
        $line = new OrderLine(PriceTierId::generate(), Quantity::of(3));
        $priced = new PricedOrderLine($line, Money::EUR(1250));

        self::assertSame($line, $priced->line());
        self::assertTrue(Money::EUR(1250)->equals($priced->unitPrice()));
        self::assertTrue(Money::EUR(3750)->equals($priced->subtotal()));
    }

    public function testAcceptsAFreeUnitPrice(): void
    {
        $priced = new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of(2)), Money::EUR(0));

        self::assertTrue($priced->subtotal()->isZero());
    }

    public function testRejectsNegativeUnitPrice(): void
    {
        $this->expectException(InvalidValueException::class);

        new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of(1)), Money::EUR(-1));
    }

    public function testRejectsOtherCurrency(): void
    {
        $this->expectException(InvalidValueException::class);

        new PricedOrderLine(new OrderLine(PriceTierId::generate(), Quantity::of(1)), Money::USD(100));
    }

    public function testEquals(): void
    {
        $line = new OrderLine(PriceTierId::generate(), Quantity::of(1));
        $other = new OrderLine(PriceTierId::generate(), Quantity::of(1));

        self::assertTrue(new PricedOrderLine($line, Money::EUR(100))->equals(new PricedOrderLine($line, Money::EUR(100))));
        self::assertFalse(new PricedOrderLine($line, Money::EUR(100))->equals(new PricedOrderLine($line, Money::EUR(101))));
        self::assertFalse(new PricedOrderLine($line, Money::EUR(100))->equals(new PricedOrderLine($other, Money::EUR(100))));
    }
}
