<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\DuplicatePriceTierException;
use App\Sales\Core\Domain\Exception\EmptyOrderException;
use App\Sales\Core\Domain\Exception\OrderTooLargeException;
use App\Sales\Core\Domain\OrderSizePolicy;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class OrderSizePolicyTest extends TestCase
{
    public function testPolicyRejects11Tickets(): void
    {
        $this->expectException(OrderTooLargeException::class);

        new OrderSizePolicy()->assertAcceptable([self::line(6), self::line(5)]);
    }

    public function testPolicyAccepts10Tickets(): void
    {
        new OrderSizePolicy()->assertAcceptable([self::line(6), self::line(4)]);

        $this->expectNotToPerformAssertions();
    }

    public function testPolicyRejectsEmptyLines(): void
    {
        $this->expectException(EmptyOrderException::class);

        new OrderSizePolicy()->assertAcceptable([]);
    }

    public function testPolicyRejectsDuplicateTier(): void
    {
        $tier = PriceTierId::generate();

        $this->expectException(DuplicatePriceTierException::class);

        new OrderSizePolicy()->assertAcceptable([
            new OrderLine($tier, Quantity::of(1)),
            new OrderLine($tier, Quantity::of(2)),
        ]);
    }

    private static function line(int $quantity): OrderLine
    {
        return new OrderLine(PriceTierId::generate(), Quantity::of($quantity));
    }
}
