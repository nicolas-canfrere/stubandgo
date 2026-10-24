<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

/**
 * Marker for messages published through the outbox and routed to the async transport.
 * Implementations carry only scalar or array properties.
 */
interface AsyncMessageInterface
{
}
