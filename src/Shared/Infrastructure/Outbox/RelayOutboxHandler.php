<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'async.bus')]
final readonly class RelayOutboxHandler
{
    public function __construct(private OutboxRelay $relay)
    {
    }

    public function __invoke(RelayOutbox $message): void
    {
        $this->relay->relayBatch();
    }
}
