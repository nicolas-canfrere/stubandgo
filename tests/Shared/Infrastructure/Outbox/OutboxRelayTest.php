<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox;

use App\Shared\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\OutboxWriterInterface;
use App\Tests\Shared\Infrastructure\Outbox\Fixtures\OutboxFixtureMessage;
use App\Tests\Shared\Infrastructure\Outbox\Fixtures\StoreOutboxMessage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class OutboxRelayTest extends KernelTestCase
{
    private OutboxWriterInterface $writer;
    private OutboxRelay $relay;
    private InMemoryTransport $transport;
    private Connection $connection;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->writer = $container->get(OutboxWriterInterface::class);
        $this->relay = $container->get(OutboxRelay::class);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
        $this->connection = $container->get(Connection::class);
    }

    public function testStoredMessageIsNotDispatchedBeforeRelay(): void
    {
        $this->writer->store(new OutboxFixtureMessage('a', 1));

        self::assertCount(0, $this->transport->getSent());
        self::assertSame(1, $this->countUnpublished());
    }

    public function testRelayDispatchesStoredMessageWithSamePayload(): void
    {
        $message = new OutboxFixtureMessage('hello', 42, ['x', 'y']);
        $this->writer->store($message);

        self::assertSame(1, $this->relay->relayBatch());

        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        self::assertEquals($message, $sent[0]->getMessage());
    }

    public function testRelayMarksMessageAsPublishedAndDoesNotResendIt(): void
    {
        $this->writer->store(new OutboxFixtureMessage('a', 1));

        self::assertSame(1, $this->relay->relayBatch());
        self::assertSame(0, $this->relay->relayBatch());

        self::assertCount(1, $this->transport->getSent());
        self::assertSame(0, $this->countUnpublished());
        self::assertNotNull($this->connection->fetchOne('SELECT published_at FROM outbox_message'));
    }

    public function testRelayRespectsBatchLimit(): void
    {
        foreach (['a', 'b', 'c'] as $text) {
            $this->writer->store(new OutboxFixtureMessage($text, 1));
        }

        self::assertSame(2, $this->relay->relayBatch(2));
        self::assertCount(2, $this->transport->getSent());
        self::assertSame(1, $this->countUnpublished());
        self::assertSame(1, $this->relay->relayBatch(2));
    }

    public function testRelayKeepsCreationOrder(): void
    {
        foreach (['a', 'b', 'c'] as $text) {
            $this->writer->store(new OutboxFixtureMessage($text, 1));
        }

        $this->relay->relayBatch();

        $texts = $this->sentTexts();
        self::assertSame(['a', 'b', 'c'], $texts);
    }

    public function testRelayOrdersByCreationDateFirst(): void
    {
        $this->insertRow('2026-01-02 00:00:00+00', 'App\Tests\Shared\Infrastructure\Outbox\Fixtures\OutboxFixtureMessage', ['text' => 'late', 'number' => 1, 'tags' => []]);
        $this->insertRow('2026-01-01 00:00:00+00', 'App\Tests\Shared\Infrastructure\Outbox\Fixtures\OutboxFixtureMessage', ['text' => 'early', 'number' => 1, 'tags' => []]);

        $this->relay->relayBatch(1);

        self::assertSame(['early'], $this->sentTexts());
    }

    public function testMessageStoredInRolledBackCommandIsDiscarded(): void
    {
        $bus = self::getContainer()->get(CommandBusInterface::class);

        try {
            $bus->dispatch(new StoreOutboxMessage('a', fail: true));
            self::fail('The handler exception should have been rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(0, $this->relay->relayBatch());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM outbox_message'));
    }

    public function testMessageStoredInCommittedCommandIsRelayed(): void
    {
        self::getContainer()->get(CommandBusInterface::class)->dispatch(new StoreOutboxMessage('a', fail: false));

        self::assertSame(1, $this->relay->relayBatch());
    }

    public function testWriterStoresRowWithUuidV7ClassAndPayload(): void
    {
        $this->writer->store(new OutboxFixtureMessage('a', 1, ['t']));

        $row = $this->connection->fetchAssociative('SELECT id, message_class, payload, created_at, published_at FROM outbox_message');
        self::assertIsArray($row);
        self::assertIsString($row['id']);
        self::assertIsString($row['payload']);
        self::assertTrue(Uuid::isValid($row['id']));
        self::assertSame('7', $row['id'][14]);
        self::assertSame(OutboxFixtureMessage::class, $row['message_class']);
        self::assertEqualsCanonicalizing(['text' => 'a', 'number' => 1, 'tags' => ['t']], json_decode($row['payload'], true));
        self::assertNotNull($row['created_at']);
        self::assertNull($row['published_at']);
    }

    public function testPoisonRowIsQuarantinedAndGoodRowsAreNotResent(): void
    {
        $this->writer->store(new OutboxFixtureMessage('good', 1));
        $this->insertRow('2030-01-01 00:00:00+00', 'App\Gone\Message', []);

        self::assertSame(1, $this->relay->relayBatch());
        self::assertSame(0, $this->relay->relayBatch());

        self::assertSame(['good'], $this->sentTexts());
        $good = $this->connection->fetchAssociative('SELECT published_at, failed_at FROM outbox_message WHERE message_class = ?', [OutboxFixtureMessage::class]);
        self::assertIsArray($good);
        self::assertNotNull($good['published_at']);
        self::assertNull($good['failed_at']);
        $poison = $this->connection->fetchAssociative("SELECT published_at, failed_at, failure_reason FROM outbox_message WHERE message_class = 'App\\Gone\\Message'");
        self::assertIsArray($poison);
        self::assertNull($poison['published_at']);
        self::assertNotNull($poison['failed_at']);
        self::assertIsString($poison['failure_reason']);
        self::assertStringContainsString('App\Gone\Message', $poison['failure_reason']);
    }

    public function testPoisonRowBeforeAValidRowDoesNotBlockIt(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', 'App\Gone\Message', []);
        $this->writer->store(new OutboxFixtureMessage('good', 1));

        self::assertSame(1, $this->relay->relayBatch());
        self::assertSame(['good'], $this->sentTexts());
    }

    public function testQuarantinedRowIsNeverSelectedAgain(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', 'App\Gone\Message', []);

        self::assertSame(0, $this->relay->relayBatch());
        $failedAt = $this->connection->fetchOne('SELECT failed_at FROM outbox_message');
        self::assertSame(0, $this->relay->relayBatch());

        self::assertSame($failedAt, $this->connection->fetchOne('SELECT failed_at FROM outbox_message'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM outbox_message WHERE published_at IS NULL AND failed_at IS NULL'));
    }

    public function testClassThatIsNotAnAsyncMessageIsQuarantined(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', \stdClass::class, []);

        self::assertSame(0, $this->relay->relayBatch());
        self::assertCount(0, $this->transport->getSent());
        self::assertStringContainsString(\stdClass::class, $this->failureReason());
    }

    public function testPayloadRejectedBySerializerIsQuarantinedWithReason(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', OutboxFixtureMessage::class, ['text' => 'only']);

        self::assertSame(0, $this->relay->relayBatch());
        self::assertCount(0, $this->transport->getSent());
        $reason = $this->failureReason();
        self::assertStringStartsWith(OutboxFixtureMessage::class . ': ', $reason);
        self::assertStringNotContainsString('only', $reason);
    }

    public function testQuarantinedRowsDoNotConsumeTheBatchLimitForOthers(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', 'App\Gone\Message', []);
        $this->writer->store(new OutboxFixtureMessage('a', 1));
        $this->writer->store(new OutboxFixtureMessage('b', 1));

        self::assertSame(1, $this->relay->relayBatch(2));
        self::assertSame(1, $this->relay->relayBatch(2));
        self::assertSame(['a', 'b'], $this->sentTexts());
    }

    private function failureReason(): string
    {
        $reason = $this->connection->fetchOne('SELECT failure_reason FROM outbox_message');
        self::assertIsString($reason);

        return $reason;
    }

    private function countUnpublished(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM outbox_message WHERE published_at IS NULL');
    }

    private function countRows(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<string>
     */
    private function sentTexts(): array
    {
        $texts = [];
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(OutboxFixtureMessage::class, $message);
            $texts[] = $message->text;
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertRow(string $createdAt, string $class, array $payload): void
    {
        $this->connection->executeStatement(
            'INSERT INTO outbox_message (id, message_class, payload, created_at) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $class, json_encode($payload, \JSON_THROW_ON_ERROR), $createdAt],
        );
    }
}
