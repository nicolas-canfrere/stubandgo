<?php

declare(strict_types=1);

namespace App\Tests\Shared\ValueObject;

use App\Shared\InvalidValueException;
use App\Shared\ValueObject\Quantity;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testQuantityRejectsNegative(): void
    {
        $this->expectException(InvalidValueException::class);

        Quantity::of(-1);
    }

    public function testQuantityRejectsZero(): void
    {
        $this->expectException(InvalidValueException::class);

        Quantity::of(0);
    }

    public function testQuantityAcceptsOne(): void
    {
        self::assertSame(1, Quantity::of(1)->toInt());
    }

    public function testQuantityKeepsValue(): void
    {
        self::assertSame(4, Quantity::of(4)->toInt());
    }

    public function testQuantityEqualsSameValue(): void
    {
        self::assertTrue(Quantity::of(3)->equals(Quantity::of(3)));
    }

    public function testQuantityDiffersFromOtherValue(): void
    {
        self::assertFalse(Quantity::of(3)->equals(Quantity::of(4)));
        self::assertFalse(Quantity::of(4)->equals(Quantity::of(3)));
    }
}
