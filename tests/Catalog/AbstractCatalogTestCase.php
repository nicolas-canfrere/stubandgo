<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class AbstractCatalogTestCase extends KernelTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
    }
}
