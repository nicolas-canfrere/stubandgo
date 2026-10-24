<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Bus;

use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Tests\Shared\Infrastructure\Fixtures\DoubleFailure;
use App\Tests\Shared\Infrastructure\Fixtures\FailingQuery;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureFailureException;
use App\Tests\Shared\Infrastructure\Fixtures\GetAnswer;
use App\Tests\Shared\Infrastructure\Fixtures\InsertRow;
use App\Tests\Shared\Infrastructure\Fixtures\RowInserted;
use App\Tests\Shared\Infrastructure\Fixtures\UnhandledEvent;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MessengerBusesTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('CREATE TEMPORARY TABLE fixture_rows (value TEXT NOT NULL)');
    }

    public function testCommandHandlerWritesAreCommitted(): void
    {
        self::getContainer()->get(CommandBusInterface::class)->dispatch(new InsertRow('a'));

        self::assertSame(['a', 'listener:a'], $this->rows());
    }

    public function testCommandHandlerExceptionRollsBackWritesAndListenerWrites(): void
    {
        try {
            self::getContainer()->get(CommandBusInterface::class)->dispatch(new InsertRow('a', fail: true));
            self::fail('The handler exception should have been rethrown.');
        } catch (FixtureFailureException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame([], $this->rows());
    }

    public function testQueryBusReturnsHandlerResult(): void
    {
        self::assertSame(42, self::getContainer()->get(QueryBusInterface::class)->ask(new GetAnswer()));
    }

    public function testQueryBusRethrowsTheOriginalException(): void
    {
        $this->expectException(FixtureFailureException::class);
        $this->expectExceptionMessage('query failed');

        self::getContainer()->get(QueryBusInterface::class)->ask(new FailingQuery());
    }

    public function testEventBusPublishesEveryEvent(): void
    {
        self::getContainer()->get(EventBusInterface::class)->publish(new RowInserted('x'), new RowInserted('y'));

        self::assertSame(['listener:x', 'listener:y'], $this->rows());
    }

    public function testEventBusWithoutEventsDispatchesNothing(): void
    {
        self::getContainer()->get(EventBusInterface::class)->publish();

        self::assertSame([], $this->rows());
    }

    public function testEventBusAllowsEventsWithoutHandler(): void
    {
        self::getContainer()->get(EventBusInterface::class)->publish(new UnhandledEvent());

        self::assertSame([], $this->rows());
    }

    public function testEventBusRethrowsTheFirstOfSeveralFailures(): void
    {
        $this->expectException(FixtureFailureException::class);
        $this->expectExceptionMessage('first');

        self::getContainer()->get(EventBusInterface::class)->publish(new DoubleFailure());
    }

    /**
     * @return list<string>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->connection->fetchFirstColumn('SELECT value FROM fixture_rows ORDER BY value') as $value) {
            self::assertIsString($value);
            $rows[] = $value;
        }

        return $rows;
    }
}
