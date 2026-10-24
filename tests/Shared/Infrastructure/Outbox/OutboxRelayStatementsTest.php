<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Checks the statements the relay sends, which the database-backed tests cannot observe:
 * a batch with nothing to publish must not issue an empty "mark as published" update.
 */
final class OutboxRelayStatementsTest extends TestCase
{
    public function testEmptyOutboxIssuesNoWrite(): void
    {
        $connection = $this->connection([]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(0, $this->relay($connection)->relayBatch());
    }

    public function testBatchOfQuarantinedRowsIssuesOnlyTheQuarantineUpdate(): void
    {
        $connection = $this->connection([['id' => 'poison', 'message_class' => 'App\Gone\Message', 'payload' => '[]']]);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(self::stringStartsWith('UPDATE outbox_message SET failed_at = :now'))
            ->willReturn(1);

        self::assertSame(0, $this->relay($connection)->relayBatch());
    }

    /**
     * @param list<array{id: string, message_class: string, payload: string}> $rows
     */
    private function connection(array $rows): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(\Closure $func): mixed => $func($connection));
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return $connection;
    }

    private function relay(Connection $connection): OutboxRelay
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        return new OutboxRelay(
            $connection,
            $this->createStub(DenormalizerInterface::class),
            $clock,
            $this->createStub(MessageBusInterface::class),
            new NullLogger(),
        );
    }
}
