<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain\ValueObject;

use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\OrderAmounts;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class OrderAmountsTest extends TestCase
{
    public function testComputeDerivesCommissionAndNet(): void
    {
        $amounts = OrderAmounts::compute(Money::EUR(1001), CommissionRate::fromBasisPoints(250));

        self::assertTrue(Money::EUR(1001)->equals($amounts->total()));
        self::assertTrue(Money::EUR(25)->equals($amounts->commission()));
        self::assertTrue(Money::EUR(976)->equals($amounts->net()));
    }

    public function testOfKeepsPersistedValues(): void
    {
        $amounts = OrderAmounts::of(Money::EUR(1000), Money::EUR(100), Money::EUR(900));

        self::assertTrue(Money::EUR(1000)->equals($amounts->total()));
        self::assertTrue(Money::EUR(100)->equals($amounts->commission()));
        self::assertTrue(Money::EUR(900)->equals($amounts->net()));
    }

    public function testEquals(): void
    {
        $amounts = OrderAmounts::of(Money::EUR(1000), Money::EUR(100), Money::EUR(900));

        self::assertTrue($amounts->equals(OrderAmounts::of(Money::EUR(1000), Money::EUR(100), Money::EUR(900))));
        self::assertFalse($amounts->equals(OrderAmounts::of(Money::EUR(1001), Money::EUR(100), Money::EUR(900))));
        self::assertFalse($amounts->equals(OrderAmounts::of(Money::EUR(1000), Money::EUR(101), Money::EUR(900))));
        self::assertFalse($amounts->equals(OrderAmounts::of(Money::EUR(1000), Money::EUR(100), Money::EUR(901))));
    }
}
