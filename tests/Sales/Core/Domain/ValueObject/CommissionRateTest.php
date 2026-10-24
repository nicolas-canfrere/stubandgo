<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain\ValueObject;

use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Shared\Exception\InvalidValueException;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommissionRateTest extends TestCase
{
    #[DataProvider('validRates')]
    public function testAcceptsBoundaries(int $value): void
    {
        self::assertSame($value, CommissionRate::fromBasisPoints($value)->toBasisPoints());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validRates(): iterable
    {
        yield 'zero' => [0];
        yield 'typical' => [250];
        yield 'maximum' => [10000];
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(InvalidValueException::class);

        CommissionRate::fromBasisPoints(-1);
    }

    public function testRejects10001(): void
    {
        $this->expectException(InvalidValueException::class);

        CommissionRate::fromBasisPoints(10001);
    }

    #[DataProvider('applications')]
    public function testApplyToRoundsHalfUp(int $basisPoints, int $totalCents, int $expectedCents): void
    {
        $commission = CommissionRate::fromBasisPoints($basisPoints)->applyTo(Money::EUR($totalCents));

        self::assertTrue(Money::EUR($expectedCents)->equals($commission));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function applications(): iterable
    {
        yield '25.025 rounds down' => [250, 1001, 25];
        yield '25.05 rounds down' => [250, 1002, 25];
        yield '25.5 rounds up' => [250, 1020, 26];
        yield 'one basis point' => [1, 20000, 2];
        yield 'one basis point half' => [1, 5000, 1];
        yield 'zero rate' => [0, 1000, 0];
        yield 'full rate' => [10000, 1234, 1234];
        yield 'typical' => [1250, 1000, 125];
    }

    public function testEquals(): void
    {
        self::assertTrue(CommissionRate::fromBasisPoints(500)->equals(CommissionRate::fromBasisPoints(500)));
        self::assertFalse(CommissionRate::fromBasisPoints(500)->equals(CommissionRate::fromBasisPoints(501)));
    }
}
