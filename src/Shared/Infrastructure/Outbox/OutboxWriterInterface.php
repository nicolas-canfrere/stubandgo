<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

interface OutboxWriterInterface
{
    /**
     * Writes the message in the current transaction; it is published later by the relay.
     */
    public function store(AsyncMessageInterface $message): void;
}
