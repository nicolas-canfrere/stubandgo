<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Observability\WorkerHeartbeat;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'async.bus')]
final readonly class RelayOutboxHandler
{
    public function __construct(private OutboxRelay $relay, private WorkerHeartbeat $heartbeat)
    {
    }

    public function __invoke(RelayOutbox $message): void
    {
        $this->relay->relayBatch();
        $this->heartbeat->beat();
    }
}
