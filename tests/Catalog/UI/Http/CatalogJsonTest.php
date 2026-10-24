<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Http;

use App\Catalog\UI\Http\CatalogJson;
use PHPUnit\Framework\TestCase;

final class CatalogJsonTest extends TestCase
{
    public function testUtcDateIsSuffixedWithZ(): void
    {
        $date = new \DateTimeImmutable('2026-11-20 19:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-11-20T19:00:00Z', CatalogJson::date($date));
    }

    public function testNonUtcDateKeepsItsRealOffset(): void
    {
        $date = new \DateTimeImmutable('2026-11-20 20:00:00', new \DateTimeZone('+01:00'));

        self::assertSame('2026-11-20T20:00:00+01:00', CatalogJson::date($date));
    }
}
