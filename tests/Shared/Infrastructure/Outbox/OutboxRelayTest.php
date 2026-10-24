<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox;

use App\Shared\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\InvalidOutboxMessageException;
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

    public function testRelayThrowsOnUnknownClassAndKeepsRowUnpublished(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', 'App\Gone\Message', []);

        $this->expectException(InvalidOutboxMessageException::class);
        $this->expectExceptionMessage('App\Gone\Message');

        try {
            $this->relay->relayBatch();
        } finally {
            self::assertSame(1, $this->countUnpublished());
        }
    }

    public function testRelayThrowsOnClassThatIsNotAnAsyncMessage(): void
    {
        $this->insertRow('2026-01-01 00:00:00+00', \stdClass::class, []);

        $this->expectException(InvalidOutboxMessageException::class);
        $this->expectExceptionMessage(\stdClass::class);

        $this->relay->relayBatch();
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
