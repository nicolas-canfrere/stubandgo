<?php

declare(strict_types=1);

namespace App\Tests\Shared\ValueObject;

use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class OrderLineTest extends TestCase
{
    public function testEqualsSameTierAndQuantity(): void
    {
        $tier = PriceTierId::generate();

        self::assertTrue(new OrderLine($tier, Quantity::of(2))->equals(new OrderLine($tier, Quantity::of(2))));
    }

    public function testDiffersWhenTierDiffers(): void
    {
        $a = new OrderLine(PriceTierId::generate(), Quantity::of(2));
        $b = new OrderLine(PriceTierId::generate(), Quantity::of(2));

        self::assertFalse($a->equals($b));
    }

    public function testDiffersWhenQuantityDiffers(): void
    {
        $tier = PriceTierId::generate();

        self::assertFalse(new OrderLine($tier, Quantity::of(2))->equals(new OrderLine($tier, Quantity::of(3))));
    }
}
