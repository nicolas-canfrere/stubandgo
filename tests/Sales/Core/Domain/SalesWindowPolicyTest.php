<?php

declare(strict_types=1);

namespace App\Tests\Sales\Core\Domain;

use App\Sales\Core\Domain\Exception\EventNotOnSaleException;
use App\Sales\Core\Domain\Exception\SalesClosedException;
use App\Sales\Core\Domain\SalesWindowPolicy;
use App\Sales\Core\Domain\ValueObject\CommissionRate;
use App\Sales\Core\Domain\ValueObject\SaleTerms;
use App\Shared\Identity\EventId;
use PHPUnit\Framework\TestCase;

final class SalesWindowPolicyTest extends TestCase
{
    private const string STARTS_AT = '2030-06-01T20:00:00+00:00';

    public function testSalesWindowRejectsDraftEvent(): void
    {
        $this->expectException(EventNotOnSaleException::class);

        new SalesWindowPolicy()->assertOpen(self::terms(false), new \DateTimeImmutable('2030-01-01T00:00:00Z'));
    }

    public function testSalesWindowRejectsAtStartTime(): void
    {
        $this->expectException(SalesClosedException::class);

        new SalesWindowPolicy()->assertOpen(self::terms(true), new \DateTimeImmutable(self::STARTS_AT));
    }

    public function testSalesWindowRejectsAfterStartTime(): void
    {
        $this->expectException(SalesClosedException::class);

        new SalesWindowPolicy()->assertOpen(self::terms(true), new \DateTimeImmutable('2030-06-02T00:00:00Z'));
    }

    public function testSalesWindowAcceptsOneSecondBeforeStart(): void
    {
        new SalesWindowPolicy()->assertOpen(self::terms(true), new \DateTimeImmutable('2030-06-01T19:59:59Z'));

        $this->expectNotToPerformAssertions();
    }

    public function testDraftEventIsReportedBeforeClosedSales(): void
    {
        $this->expectException(EventNotOnSaleException::class);

        new SalesWindowPolicy()->assertOpen(self::terms(false), new \DateTimeImmutable('2031-01-01T00:00:00Z'));
    }

    private static function terms(bool $published): SaleTerms
    {
        return SaleTerms::of(EventId::generate(), $published, new \DateTimeImmutable(self::STARTS_AT), CommissionRate::fromBasisPoints(0), []);
    }
}
