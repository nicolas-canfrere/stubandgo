<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class OutboxRelay
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'async.bus')]
        private readonly MessageBusInterface $asyncBus,
    ) {
    }

    /**
     * Publishes up to $limit unpublished messages, oldest first, and returns how many were relayed.
     * Select, dispatch and update share one transaction so the row locks last until the batch is marked.
     */
    public function relayBatch(int $limit = 50): int
    {
        return $this->connection->transactional(function () use ($limit): int {
            /** @var list<array{id: string, message_class: string, payload: string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, message_class, payload FROM outbox_message WHERE published_at IS NULL ORDER BY created_at, id LIMIT :limit FOR UPDATE SKIP LOCKED',
                ['limit' => $limit],
            );

            foreach ($rows as $row) {
                $this->asyncBus->dispatch($this->denormalize($row['message_class'], $row['payload']));
            }

            $this->markAsPublished(array_column($rows, 'id'));

            return \count($rows);
        });
    }

    private function denormalize(string $class, string $payload): AsyncMessageInterface
    {
        if (!is_a($class, AsyncMessageInterface::class, true)) {
            throw InvalidOutboxMessageException::forClass($class);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);

        /** @var AsyncMessageInterface $message */
        $message = $this->denormalizer->denormalize($data, $class);

        return $message;
    }

    /**
     * @param list<string> $ids
     */
    private function markAsPublished(array $ids): void
    {
        $this->connection->executeStatement(
            'UPDATE outbox_message SET published_at = :now WHERE id IN (:ids)',
            ['now' => $this->clock->now()->format('Y-m-d H:i:s.uP'), 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
