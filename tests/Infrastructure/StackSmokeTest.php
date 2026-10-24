<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StackSmokeTest extends KernelTestCase
{
    public function testDatabaseIsReachable(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }
}
