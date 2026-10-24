<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

/**
 * Marker for messages published through the outbox and routed to the async transport.
 * Implementations carry only scalar or array properties. Their constructor guards must not echo their input
 * in exception messages: the message ends up in logs and in the outbox failure_reason column.
 */
interface AsyncMessageInterface
{
}
