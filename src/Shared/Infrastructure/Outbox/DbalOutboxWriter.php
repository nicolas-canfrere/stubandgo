<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Observability\CorrelationContext;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOutboxWriter implements OutboxWriterInterface
{
    public function __construct(
        private Connection $connection,
        private NormalizerInterface $normalizer,
        private ClockInterface $clock,
        private CorrelationContext $correlation,
    ) {
    }

    public function store(AsyncMessageInterface $message): void
    {
        $this->connection->executeStatement(
            'INSERT INTO outbox_message (id, message_class, payload, created_at, trace_parent) VALUES (:id, :class, :payload, :created_at, :trace_parent)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'class' => $message::class,
                'payload' => json_encode($this->normalizer->normalize($message), \JSON_THROW_ON_ERROR),
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
                'trace_parent' => $this->correlation->current()->toHeader(),
            ],
        );
    }
}
