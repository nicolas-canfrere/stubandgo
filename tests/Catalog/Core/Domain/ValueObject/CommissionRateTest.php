<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain\ValueObject;

use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Shared\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommissionRateTest extends TestCase
{
    #[DataProvider('validRates')]
    public function testCommissionRateAcceptsBoundaries(int $value): void
    {
        self::assertSame($value, CommissionRate::fromBasisPoints($value)->toBasisPoints());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validRates(): iterable
    {
        yield 'zero' => [0];
        yield 'typical' => [1250];
        yield 'maximum' => [10000];
    }

    public function testCommissionRateRejectsNegative(): void
    {
        $this->expectException(InvalidValueException::class);

        CommissionRate::fromBasisPoints(-1);
    }

    public function testCommissionRateRejects10001(): void
    {
        $this->expectException(InvalidValueException::class);

        CommissionRate::fromBasisPoints(10001);
    }

    public function testCommissionRateEquals(): void
    {
        self::assertTrue(CommissionRate::fromBasisPoints(500)->equals(CommissionRate::fromBasisPoints(500)));
        self::assertFalse(CommissionRate::fromBasisPoints(500)->equals(CommissionRate::fromBasisPoints(501)));
        self::assertFalse(CommissionRate::fromBasisPoints(501)->equals(CommissionRate::fromBasisPoints(500)));
    }
}
