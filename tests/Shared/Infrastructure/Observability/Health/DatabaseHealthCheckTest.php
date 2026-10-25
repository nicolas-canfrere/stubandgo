<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Health;

use App\Shared\Infrastructure\Observability\Health\DatabaseHealthCheck;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseHealthCheckTest extends KernelTestCase
{
    public function testItIsNamedDatabase(): void
    {
        self::assertSame('database', self::getContainer()->get(DatabaseHealthCheck::class)->name());
    }

    public function testItIsHealthyWhenTheDatabaseAnswers(): void
    {
        $status = self::getContainer()->get(DatabaseHealthCheck::class)->check();

        self::assertTrue($status->healthy);
    }

    public function testItReportsAnUnreachableDatabaseWithoutLeakingTheError(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('password authentication failed for s3cret'));

        $status = new DatabaseHealthCheck($connection)->check();

        self::assertFalse($status->healthy);
        self::assertStringNotContainsString('s3cret', $status->detail);
    }
}
