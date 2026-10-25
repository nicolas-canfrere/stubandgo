<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Observability\CorrelationStamp;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Publishes up to $limit pending messages, oldest first, and returns how many were relayed.
     * Rows that cannot be rebuilt are quarantined (failed_at, failure_reason) instead of blocking the outbox.
     * Select, dispatch and updates share one transaction so the row locks last until the batch is marked.
     * Each message carries the trace of the request that stored it.
     */
    public function relayBatch(int $limit = 50): int
    {
        return $this->connection->transactional(function () use ($limit): int {
            /** @var list<array{id: string, message_class: string, payload: string, trace_parent: ?string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, message_class, payload, trace_parent FROM outbox_message WHERE published_at IS NULL AND failed_at IS NULL ORDER BY created_at, id LIMIT :limit FOR UPDATE SKIP LOCKED',
                ['limit' => $limit],
            );

            $messages = [];
            foreach ($rows as $row) {
                try {
                    $messages[$row['id']] = new Envelope(
                        $this->denormalize($row['message_class'], $row['payload']),
                        null === $row['trace_parent'] ? [] : [new CorrelationStamp($row['trace_parent'])],
                    );
                } catch (\Throwable $exception) {
                    $this->quarantine($row['id'], $row['message_class'], $exception);
                }
            }

            foreach ($messages as $message) {
                $this->asyncBus->dispatch($message);
            }

            $this->markAsPublished(array_keys($messages));

            return \count($messages);
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

    private function quarantine(string $id, string $class, \Throwable $exception): void
    {
        $reason = $exception instanceof InvalidOutboxMessageException ? $exception->getMessage() : \sprintf('%s: %s', $class, $exception->getMessage());

        $this->connection->executeStatement(
            'UPDATE outbox_message SET failed_at = :now, failure_reason = :reason WHERE id = :id',
            ['now' => $this->now(), 'reason' => $reason, 'id' => $id],
        );
        $this->logger->error('Outbox message quarantined.', ['outbox_message_id' => $id, 'message_class' => $class, 'reason' => $reason]);
    }

    /**
     * @param list<string> $ids
     */
    private function markAsPublished(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE outbox_message SET published_at = :now WHERE id IN (:ids)',
            ['now' => $this->now(), 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.uP');
    }
}
