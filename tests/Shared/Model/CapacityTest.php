<?php

declare(strict_types=1);

namespace App\Tests\Shared\Model;

use App\Shared\InvalidValueException;
use App\Shared\Model\Capacity;
use PHPUnit\Framework\TestCase;

final class CapacityTest extends TestCase
{
    public function testCapacityRejectsZero(): void
    {
        $this->expectException(InvalidValueException::class);

        Capacity::of(0);
    }

    public function testCapacityRejectsNegative(): void
    {
        $this->expectException(InvalidValueException::class);

        Capacity::of(-1);
    }

    public function testCapacityAcceptsOne(): void
    {
        self::assertSame(1, Capacity::of(1)->toInt());
    }

    public function testCapacityKeepsValue(): void
    {
        self::assertSame(250, Capacity::of(250)->toInt());
    }
}
